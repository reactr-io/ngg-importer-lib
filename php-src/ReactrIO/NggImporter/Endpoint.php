<?php

namespace ReactrIO\NggImporter;

class Endpoint
{
    const VERSION='v1';

    protected static $_instances = array();
    public static function get_instance($endpoint_name, $max_execution=30)
    {
        if (!isset(self::$_instances[$endpoint_name])) {
            $klass = get_called_class();
            self::$_instances[$endpoint_name] = new $klass($endpoint_name, $max_execution);
        }
        $retval = self::$_instances[$endpoint_name];
        $retval->max_execution = $max_execution;
        return $retval;
    }

    function __construct($endpoint_name, $max_execution=30)
    {
        $this->max_execution = $max_execution;
        $this->endpoint_name = $endpoint_name;

        add_action('rest_api_init', function() use ($endpoint_name) {
            register_rest_route( $endpoint_name . '/' . self::VERSION, '/import', array(
                'methods' => 'POST,GET',
                'callback' => array($this, 'endpoint'),
                'permission_callback' => function(){
                    return \current_user_can('administrator');
                }
            ));
        });
    }

    function validate_request(\WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        if (isset($params['filename'])) return $params['filename'];

        throw E_FileImporter::create("Filename not specified", $params);
    }

    function endpoint(\WP_REST_Request $request)
    {
        try {
            $filename = $this->validate_request($request);
            $importer = new FileImporter($filename, $this->max_execution);

            return $importer->run();
        }
        catch (E_FileImporter $ex) {
            return $ex->getContext();
        }
        catch (\Exception $ex) {
            return array(
                'error_msg'     => $ex->getMessage(),
                'error_code'    => 'Fatal'
            );
        }
    }
}