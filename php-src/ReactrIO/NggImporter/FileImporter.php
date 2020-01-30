<?php

namespace ReactrIO\NggImporter;

class FileImporter
{
    protected $elapsed = 0;
    protected $max_time_limit = 30;
    protected $filename = '';
    protected $file_abspath = '';
    protected $i = 0;
    protected $max_retries = 10;
    protected $current_object = NULL;

    /**
     * Performs a step required for processing a file, and tracks how much time has elapsed
     */
    protected function _do_step($step, $retval)
    {
        $exception = NULL;
        $started_at = microtime(TRUE);
        $func = "step_{$step}";
        $retval = $this->$func($retval);
        $ended_at = microtime(TRUE);
        $time_spent = $ended_at - $started_at;
        $this->elapsed += $time_spent;
        return $retval;
    }

    function __construct($filename, $max_time_limit=30, $max_retries=10)
    {
        $this->max_time_limit = $max_time_limit;
        $this->max_retries = $max_retries;
        $this->filename = $filename;
        $this->file_abspath = $this->get_file_abspath($this->filename);
        $this->validate();
    }

    function validate()
    {
        $this->ensure_file_exists();
        $this->ensure_file_is_writable();
    }

    function ensure_file_exists()
    {
        if (!file_exists($this->file_abspath) || !is_readable($this->file_abspath)) throw E_FileImporter::create(
            "{$this->filename} does not exist or is not readable.",
            $this->get_state()
        );
    }

    function ensure_file_is_writable()
    {
        if (!is_writable($this->file_abspath)) {
            if (!chmod($this->file_abspath, 770)) {
                throw E_FileImporter::create(
                    "Cannot write to {$this->filename}. Please check permissions",
                    $this->get_state()
                );
            }
        }
    }

    function run()
    {
        $this->reset();
        $this->do_steps(['parse', 'import_objects']);
        $this->step_write_status(); // step must be done

        return $this->get_state(['file_abspath']);
    }

    function get_from_status($prop)
    {
        return 
            (isset($this->data) && is_object($this->data) && is_object($this->data->status) && is_int($this->data->status->$prop))
            ? $this->data->status->$prop
            : 0;
    }

    function get_state($exclude=[], $add=[])
    {
        $exclude[] = 'data';
        $exclude[] = 'current_object';

        $add['done']    = $this->get_from_status('done');
        $add['skipped'] = $this->get_from_status('skipped');
        $add['aborted'] = $this->get_from_status('aborted');
        $add['total'] = $this->get_from_status('total');

        // Note, really wish the PHP pipe operator was available: https://wiki.php.net/rfc/pipe-operator
        $r = get_object_vars($this);
        $r = array_filter($r, function($val, $key) use ($exclude) {return !in_array($key, $exclude);}, ARRAY_FILTER_USE_BOTH);
        $r = array_merge($r, $add);

        return $r;
    }

    function do_steps($steps=[], $retval=NULL)
    {
        foreach ($steps as $step) {
            if ($this->has_reached_time_limit()) {
                $retval = FALSE;
                break;
            }
            $retval = $this->_do_step($step, $retval);
        }

        return $retval;
    }

    function has_reached_time_limit()
    {
        return $this->elapsed >= $this->max_time_limit;
    }

    function step_parse()
    {
        $data = FALSE;
        $contents = @file_get_contents($this->file_abspath);
        if ($contents) {
            $data = @json_decode($contents);
        }
        
        if ($data === FALSE || $contents == FALSE) throw E_FileImporter::create(
            "Could not parse {$this->filename}.",
            $this->get_state([], ['contents' => $contents])
        );

        $this->data = $data;

        $this->initialize_data();

        return $this->data;
    }

    function initialize_data()
    {
        if (!is_array($this->data->objects)) $this->data->objects = (array) $this->data->objects;
        if (!isset($this->data->skipped_galleries)) $this->data->skipped_galleries = 0;
        if (!isset($this->data->errors)) $this->data->errors = array();

        // Resets indexes
        $this->data->objects = array_values($this->data->objects);
    }

    function step_write_status($retval=NULL)
    {
        $bytes_written = FALSE;
        $json = @json_encode($this->data);
        if ($json) {
            $bytes_written = @file_put_contents($this->file_abspath, $json);
        }

        if ($json === FALSE || $bytes_written === 0 || $bytes_written === FALSE) throw E_FileImporter::create(
            "Could not write status to {$this->filename}",
            $this->get_state([], ['json' => $json])
        );

        return $retval;
    }

    function step_import_objects($retval)
    {
        while ($this->can_continue_importing()) {
            $this->mark_current_object();
            if (!$this->maybe_abort_object()) {
                switch ($this->data->type) {
                    case 'galleries':
                        $retval = $this->do_steps(['import_gallery']);
                        break;
                    case 'albums':
                        $retval = $this->do_steps(['import_album']);
                        break;
                    case 'images':
                        $retval = $retval = $this->do_steps(['import_image']);
                        break;
                }
            }
        }

        return $retval;
    }

    function maybe_abort_object()
    {
        if (!$this->can_object_be_retried()) {
            $this->mark_object_as_aborted();
            return TRUE;
        }

        return FALSE;
    }

    function can_object_be_retried()
    {
        return (!$this->object_has_flag('retry_i')) || ($this->object_has_flag('retry_i') && $this->get_object_flag('retry_i') < $this->max_retries);
    }

    function mark_current_object()
    {
        $this->current_object = $this->data->objects[0];
        if (!$this->object_has_flag('retry_i')) $this->set_object_flag('retry_i', 0);
        else $this->set_object_flag('retry_i', $this->get_object_flag('retry_i')+1);
        if (is_array($this->current_object)) $this->current_object = (object) $this->current_object;
        if (isset($this->current_object->urls) && is_array($this->current_object->urls)) {
            $this->current_object->urls = (object) $this->current_object->urls;
        }
        return $this->current_object;
    }

    function purge_current_object()
    {
        unset($this->data->objects[0]);
        $this->current_object = NULL;
        $this->data->objects = array_values($this->data->objects);
    }

    function mark_object_as_done()
    {
        $this->data->status->done++;
        $this->purge_current_object();
        
    }

    function mark_object_as_skipped()
    {
        $this->data->status->skipped++;
        $this->purge_current_object();
    }

    function mark_object_as_aborted()
    {
        $this->data->status->aborted++;
        $this->purge_current_object();
    }

    function mark_gallery_as_skipped()
    {
        $this->mark_object_as_skipped();
        $this->data->skipped_galleries++;
    }

    function step_import_gallery($retval=NULL)
    {
        return $this->with_modified_datamapper(\C_Gallery_Mapper::get_instance(), function($mapper) use ($retval){
            $gallery    = &$this->current_object;

            // Remove stale data
            unset($gallery->extras_post_id);
            unset($gallery->path);

            // Assign an author
            if (($author = $this->get_author())) {
                $gallery->author = $author;
            }

            // Skip this gallery if we already have a gallery with this ID
            if ($mapper->find($gallery)) {
                return $this->mark_gallery_as_skipped();
            }

            // Try importing the gallery
            return $this->try_importing_object(
                'import_gallery',
                function($err_msg) use ($mapper, $gallery) {
                    $retval = $mapper->save($gallery);
                    if (!$retval) throw new RuntimeException($err_msg);
                    return $retval;
                },
                "Could not import gallery ID {$gallery->gid}"
            );
        });   
    }

    function step_import_album($retval=NULL)
    {
        return $this->with_modified_datamapper(\C_Album_Mapper::get_instance(), function($mapper) use ($retval){
            $album    = &$this->current_object;

            // Remove stale data
            unset($album->extras_post_id);

            // Skip this album if we already have a album with this ID
            if ($mapper->find($album)) {
                return $this->mark_object_as_skipped();
            }

            // Try importing the album
            return $this->try_importing_object(
                'import_album',
                function($err_msg) use ($mapper, $album) {
                    $retval = $mapper->save($album);
                    if (!$retval) throw new RuntimeException($err_msg);
                    return $retval;
                },
                "Could not import album ID {$album->id}"
            );
        });
    }

    function object_has_flag($flag)
    {
        $prop = "flag_{$flag}";
        return property_exists($this->current_object, $prop) && $this->current_object->$prop !== FALSE;
    }

    function set_object_flag($flag, $val=TRUE)
    {
        $prop = "flag_{$flag}";
        if ($this->current_object) {
            $this->current_object->$prop = $val;    
            return $val;
        }
        return NULL;
    }

    function unset_object_flag($flag)
    {
        $prop = "flag_{$flag}";
        unset($this->current_object->$prop);    
    }

    function get_object_flag($flag)
    {
        $prop = "flag_{$flag}";

        return $this->object_has_flag($flag)
            ? $this->current_object->$prop
            : NULL;
    }

    function get_object_flags()
    {
        $retval = array();

        foreach (get_object_vars($this->current_object) as $key => $val) {
            if (strpos($key, 'flag') === 0) $retval[$key] = $val;
        }

        return $retval;
    }

    function remove_object_flags()
    {
        foreach ($this->get_object_flags() as $flag) $this->unset_object_flag($flag);
    }

    function restore_object_flags($flags)
    {
        foreach ($flags as $key => $value) {
            $key = preg_replace("/^flag_/", '', $key);

            // $flags = ['imported_db' => TRUE]
            if (is_string($key))    $this->set_object_flag($key, $value);
            
            // $flags = ['imported_db']
            else if (is_int($key))  $this->set_object_flag($value);
        }
    }

    function step_import_image($retval=NULL)
    {
        $steps = array();
        if (!$this->object_has_flag('imported_db'))         $steps[] = 'import_image_db';
        if (!$this->has_imported_image_size('backup'))      $steps[] = 'import_image_backup';
        if (!$this->has_imported_image_size('full'))        $steps[] = 'import_image_full';
        if (!$this->has_imported_image_size('thumbnail'))   $steps[] = 'import_image_thumbnail';

        if (!$steps) $this->mark_object_as_done();

        return $this->do_steps($steps, $retval);
    }

    function step_import_image_db($retval=NULL)
    {
        return $this->with_modified_datamapper(\C_Image_Mapper::get_instance(), function($mapper) use ($retval) {

            $image    = &$this->current_object;

            // Store information we're about to unset. We'll need it if we have to revert on failure
            $orig_data = array(
                'urls'              =>  $image->urls,
                'extras_post_id'    =>  $image->extras_post_id,
                'meta_data'         =>  $image->meta_data,
                'tags'              =>  property_exists($image, 'tags') ? $image->tags : array()
            );

            // Remove stale data
            unset($image->urls);
            unset($image->extras_post_id);
            unset($image->tags);

            // Update image metadata
            $meta_data              = array();
            $existing_meta          = isset($image->meta_data) ? get_object_vars($image->meta_data) : array();
            foreach ($existing_meta as $key => $var) {
                if (is_object($var)) $var = (array) $var;
                $meta_data[$key]    = $var;
            }
            $image->meta_data       = $meta_data;

            // Try importing the image
            return $this->try_importing_object(
                'import_image_db',
                function($err_msg) use ($mapper, $image, $orig_data) {
                    $retval = $mapper->save($image);

                    // Restore original data
                    foreach ($orig_data as $k=>$v) $this->current_object->$k = $v;

                    if (!$retval) error_log("Could not save image:");

                    if (!$retval) throw new RuntimeException($err_msg);
                    
                    // Flag as done
                    $this->set_object_flag('imported_db');

                    // Import tags
                    if ($orig_data['tags']) {
                        wp_set_object_terms($image->pid, $orig_data['tags'], 'ngg_tag');
                    }

                    return $retval;
                },
                "Could not import image ID {$image->pid}"
            );
        });
    }

    function get_percentage_of_time_remaining()
    {
        return 100 - (($this->elapsed / $this->max_time_limit)*100);
    }

    function get_time_remaining($buffer=0)
    {
        $retval = $this->max_time_limit - $this->elapsed - 0 - $buffer;
        if ($retval < 0) $retval = 0;
        return $retval;
    }

    function step_import_image_backup($retval)
    {
        return $this->_import_image_file('backup', $retval);
    }

    function get_image_size_flag($image_size)
    {
        return "imported_img_size_{$image_size}";
    }

    function mark_image_sized_as_imported($image_size)
    {
        $this->set_object_flag($this->get_image_size_flag($image_size));
    }

    function has_imported_image_size($image_size)
    {
        return $this->object_has_flag($this->get_image_size_flag($image_size));
    }

    function _import_image_file($image_size, $retval)
    {
        if ($retval === FALSE) {
            error_log("Previous step failed. Skip importing {$image_size} for: {$this->current_object->pid}");
            return FALSE;
        }

        $image = &$this->current_object;

        if (!property_exists($image->urls, $image_size)) {
            $this->mark_image_sized_as_imported($image_size);
            return;
        }

        // Fetching the backup image can take a lot of time
        // The time elapsed thus far is greater than 20% of the max time
        // available, then we should probably leave this operation for the
        // next request
        if ($this->get_percentage_of_time_remaining() >= 80) { // 80% of time remaining
            return $this->try_importing_object(
                "step_import_image_{$image_size}",
                function() use ($image_size, $retval) {
                    $retval = $this->_download_image_file($image_size, $retval);
                    if ($retval) $this->mark_image_sized_as_imported($image_size);
                    return $retval;
                },
                "Cannot download backup image for {$image->pid}"
            );
        }

        error_log("Could not import {$image_size} for {$image->pid}. Not enough time");
        $this->elapsed = $this->max_time_limit;
        return FALSE;        
    }

    protected function _download_image_file($image_size, $retval)
    {
        $image  = &$this->current_object;
        $image->meta_data = isset($image->meta_data) && is_object($image->meta_data)
            ? get_object_vars($image->meta_data)
            : array();

        $url    = $image->urls->$image_size;
        $storage = \C_Gallery_Storage::get_instance();
        $image_abspath = $storage->get_image_abspath($image, $image_size, FALSE);
        $temp_abspath = tempnam(sys_get_temp_dir(), 'ngg');
        $gallery_abspath = dirname($image_abspath);
        wp_mkdir_p(dirname($temp_abspath));
        if (!wp_mkdir_p($gallery_abspath)) {
            throw new RuntimeException("Could not create directory, {$gallery_abspath}");
        }

        $timeout = $this->get_time_remaining(400/1000); 
        if (!$timeout) {
            error_log("Not enough time remaining to download {$image_size} for {$image->pid}");
            return FALSE;
        }

        $retval = $response = wp_remote_get($url, array(
            'stream'    => TRUE,
            'filename'  => $temp_abspath,
            'timeout'   => $timeout,
            'sslverify' => FALSE
        ));

        // Were we successful?
        if (!is_array($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            error_log("Could not fetch {$url}");
            error_log(print_r($response, TRUE));
            $this->elapsed = $this->max_time_limit;
            throw new RuntimeException("Could not download {$url}");
        } else {
            @copy($temp_abspath, $image_abspath);
            @unlink($temp_abspath);
            if (!@file_exists($image_abspath)) {
                throw new RuntimeException("We were able to download {$url}, but could not move it from {$temp_abspath} to {$image_abspath}");
            }
        }

        // Some hosts expect file permissions to be 755 or higher
        try {
            @chmod($image_abspath, 775);
            @system("chmod ug+rwx {$image_abspath}");
        }
        catch (\Exception $ex) {

        }

        $this->set_object_flag('retry_i', 0);

        return $retval;
    }

    function step_import_image_full($retval)
    {
        return $this->_import_image_file('full', $retval);
    }

    function step_import_image_thumbnail($retval)
    {
        return $this->_import_image_file('thumbnail', $retval);
    }

    function try_importing_object($steps, $callback, $err_msg)
    {
        $flags = $this->get_object_flags();
        $this->remove_object_flags();

        // Try to save the gallery
        try {
            return $callback($err_msg);
        }
        catch (\Exception $ex) {
            error_log("Exception raised");
            error_log($ex->getMessage());
            $this->restore_object_flags($flags);
            if ($this->can_object_be_retried()) {
                $this->mark_current_object();
                return $this->has_reached_time_limit()
                    ? FALSE
                    : $this->try_importing_object(
                        $steps,
                        $callback,
                        $err_msg
                    );
            }
            else {
                $this->mark_object_as_aborted($err_msg);
                return FALSE;
            }
        }
    }

    function with_modified_datamapper($mapper_instance, $callback)
    {
        require_once('includes/modify_datamapper.php');
        $error              = NULL;
        $retval             = NULL;
        $modified_mapper    = modify_datamapper($mapper_instance);
        $modified_mapper->force_create = TRUE;
        try {
            $retval = $callback($modified_mapper);
        }
        catch (\Exception $ex)
        {
            $error = $ex;
        }
        $modified_mapper->force_create = FALSE;

        if ($error) throw $error;

        return $retval;
    }

    function can_continue_importing()
    {
        return count($this->data->objects) && !$this->has_reached_time_limit() && !$this->is_finished();
    }

    function is_finished()
    {
        return $this->data->status->total === ($this->data->status->done + $this->data->status->aborted + $this->data->status->skipped);
    }

    function get_author()
    {
        $users = get_users(array(
			'role'      =>  'administrator',
			'blog_id'   =>  get_current_blog_id()
		));
        
        return $users ? $users[0]->ID : FALSE;
    }

    function get_file_abspath($filename)
    {
        return apply_filters('ngg_import_filename', path_join(wp_upload_dir()['basedir'], $filename), $filename);
    }

    function reset()
    {
        $this->elapsed = 0;
    }
}