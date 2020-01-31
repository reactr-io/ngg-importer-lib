<?php

namespace ReactrIO\NggImporter;

/**
 * DataMappers by default, when you provide data to save in the database, will assume that you're trying
 * to update an existing record if a value for the primary key is provided.
 *
 * We modify the mapper's behaviour here by introducing a force_create property for the mapper.
 *
 * @param $mapper
 *
 * @return \ExtensibleObject
 */
function modify_datamapper($mapper) {
	if (defined('NGG_PLUGIN_VERSION') && !class_exists('Ngg_Export_Mapper_Mixin', FALSE)) {
		class Ngg_Export_Mapper_Mixin extends \Mixin
		{
			/**
			 * Stores the entity
			 * @param stdClass $entity
			 */
			function _save_entity($entity)
			{
				$retval = FALSE;

				unset($entity->id_field);
				$primary_key = $this->object->get_primary_key_column();
				if (isset($entity->$primary_key) && $entity->$primary_key > 0 && !$this->object->force_create) {
					if($this->object->_update($entity)) $retval = intval($entity->$primary_key);
				}
				else {
					$retval = $this->object->_create($entity);
					if ($retval) {
						$new_entity = $this->object->find($retval);
						foreach ($new_entity as $key => $value) $entity->$key = $value;
					}
				}
				$entity->id_field = $primary_key;

				// Clean cache
				if ($retval) {
					$this->object->_cache = array();
				}

				return $retval;
			}
		}
	}

	// Apply mixin to mapper
	if (!$mapper->has_mixin('Ngg_Export_Mapper_Mixin')) {
		/**
		 * @var $mapper ExtensibleObject
		 */
		$mapper->add_mixin('Ngg_Export_Mapper_Mixin', TRUE);
		$priorities = array();

		// Remove Ngg_Export_Mapper_Mixin
		array_shift($mapper->_mixin_priorities);

		// Pop Mixin_DataMapper_Driver_Base
		$priorities[] = array_pop($mapper->_mixin_priorities);

		// Pop C_CustomTable_DataMapper_Driver_Mixin
		$priorities[] = array_pop($mapper->_mixin_priorities);

		$mapper->_mixin_priorities[] = 'Ngg_Export_Mapper_Mixin';
		foreach ($priorities as $mixin) $mapper->_mixin_priorities[] = $mixin;
		$mapper->_flush_cache();
	}

	return $mapper;
}