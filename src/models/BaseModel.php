<?php
namespace Blocks;

/**
 * @abstract
 */
abstract class BaseModel extends \CActiveRecord
{
	protected $tableName;
	protected $contentJoinTableName;
	protected $blocksJoinTableName;
	protected $settingsTableName;
	protected $foreignKeyName;
	protected $classPrefix = '';
	protected $classSuffix = '';

	protected $hasContent = false;
	protected $hasBlocks = false;
	protected $hasSettings = false;

	protected $defaultSettings = array();
	protected $attributes = array();
	protected $belongsTo = array();
	protected $hasMany = array();
	protected $hasOne = array();
	protected $indexes = array();

	protected $_classHandle;
	protected $_content;
	protected $_blocks;
	protected $_settings;

	/**
	 * Constructor
	 * @param string $scenario
	 */
	public function __construct($scenario = 'insert')
	{
		// If Blocks isn't installed, this model's table won't exist yet,
		// so just create an instance of the class, for use by the installer
		if (!b()->isInstalled)
		{
			// Just do the bare minimum of constructor-type stuff.
			// Maybe init() is all that's necessary?
			$this->init();
		}
		else
			parent::__construct($scenario);
	}

	/**
	 * Get the class name, sans namespace
	 */
	public function getClassHandle()
	{
		if (!isset($this->_classHandle))
		{
			// Chop off the namespace
			$classHandle = substr(get_class($this), strlen(__NAMESPACE__)+1);

			// Chop off the class prefix
			$prefixLength = strlen($this->classPrefix);
			if (substr($classHandle, 0, $prefixLength) == $this->classPrefix)
				$classHandle = substr($classHandle, $prefixLength);

			// Chop off the class suffix
			$suffixLength = strlen($this->classSuffix);
			if (substr($classHandle, -$suffixLength) == $this->classSuffix)
				$classHandle = substr($classHandle, 0, -$suffixLength);

			$this->_classHandle = $classHandle;
		}

		return $this->_classHandle;
	}

	/**
	 * Used by CActiveRecord
	 * @return string The model's table name
	 */
	public function tableName()
	{
		return '{{'.$this->getTableName().'}}';
	}

	/**
	 * Get the model's table name (without the curly brackets)
	 * @return string The table name
	 * @access protected
	 */
	protected function getTableName()
	{
		if (isset($this->tableName))
			return $this->tableName;
		else
			return strtolower($this->getClassHandle());
	}

	/**
	 * Get the model's content join table name
	 * @return string The table name
	 * @access protected
	 */
	protected function getContentJoinTableName()
	{
		if (isset($this->contentJoinTableName))
			return $this->contentJoinTableName;
		else
			return strtolower($this->getClassHandle()).'content';
	}

	/**
	 * Get the model's content blocks join table name
	 * @return string The table name
	 * @access protected
	 */
	protected function getBlocksJoinTableName()
	{
		if (isset($this->blocksJoinTableName))
			return $this->blocksJoinTableName;
		else
			return strtolower($this->getClassHandle()).'blocks';
	}

	/**
	 * Get the model's settings table name
	 * @return string The table name
	 * @access protected
	 */
	protected function getSettingsTableName()
	{
		if (isset($this->settingsTableName))
			return $this->settingsTableName;
		else
			return strtolower($this->getClassHandle()).'settings';
	}

	/**
	 * Get the model's foreign key name
	 * (Used when defining content block, content, and settings tables)
	 * @return string The foreign key name
	 * @access protected
	 */
	protected function getForeignKeyName()
	{
		if (isset($this->foreignKeyName))
			return $this->foreignKeyName;
		else
			return strtolower($this->getClassHandle()).'_id';
	}

	/**
	 * Returns the content assigned to this record
	 * @return array
	 */
	public function getContent()
	{
		if (!isset($this->_content))
		{
			if ($this->hasContent && !$this->isNewRecord)
			{
				$content = b()->db->createCommand()
					->select('c.*')
					->from($this->getContentJoinTableName().' j')
					->join('content c', 'j.content_id = c.id')
					->where(
						array('and', 'j.'.$this->getForeignKeyName().' = :id', 'j.active = 1'),
						array(':id' => $this->id)
					)
					->order('j.num desc')
					->queryRow();

				if ($content)
					$this->_content = Content::model()->populateRecord($content);
			}

			if (!isset($this->_content))
				$this->_content = new Content;
		}

		return $this->_content;
	}

	/**
	 * Returns the content blocks assigned to this record
	 * @return array
	 */
	public function getBlocks()
	{
		if (!isset($this->_blocks))
		{
			$this->_blocks = array();

			if ($this->hasBlocks && !$this->isNewRecord)
			{
				$blocks = b()->db->createCommand()
					->select('j.required, b.*')
					->from($this->getBlocksJoinTableName().' j')
					->join('blocks b', 'j.block_id = b.id')
					->where('j.'.$this->getForeignKeyName().' = :id', array(':id' => $this->id))
					->order('j.sort_order')
					->queryAll();

				foreach ($blocks as $block)
				{
					$class = __NAMESPACE__.'\\'.$block['class'].'Blocktype';
					$this->_blocks[] = $class::model()->populateRecord($block);
				}
			}
		}

		return $this->_blocks;
	}

	/**
	 * Sets the content blocks
	 * @param array $blocks
	 */
	public function setBlocks($blocks)
	{
		$this->_blocks = $blocks;
	}

	/**
	 * Returns the current record's settings
	 */
	public function getSettings()
	{
		if (!isset($this->_settings))
		{
			$this->_settings = $this->defaultSettings;

			if ($this->hasSettings && !$this->isNewRecord)
			{
				$settings = b()->db->createCommand()
					->select('s.name, s.value')
					->from($this->getSettingsTableName().' s')
					->where('s.'.$this->getForeignKeyName().' = :id', array(':id' => $this->id))
					->queryAll();

				if ($settings)
				{
					$flattened = array();
					foreach ($settings as $setting)
					{
						$flattened[$setting['name']] = $setting['value'];
					}
					$expanded = ArrayHelper::expandArray($flattened);
					$this->_settings = array_merge($this->_settings, $expanded);
				}
			}
		}

		return $this->_settings;
	}

	/**
	 * Sets the current record's settings
	 */
	public function setSettings($settings)
	{
		$this->_settings = array_merge($this->defaultSettings, (array)$settings);

		if (!$this->isNewRecord)
		{
			$table = $this->getSettingsTableName();

			// Delete the previous settings
			b()->db->createCommand()->delete($table, $this->getForeignKeyName().' = :id', array(':id' => $this->id));

			// Save the new ones
			if ($this->_settings)
			{
				$flattened = ArrayHelper::flattenArray($this->_settings);
				if ($flattened)
				{
					foreach ($flattened as $name => $value)
					{
						$vals[] = array($this->id, $name, $value);
					}
					$columns = array($this->getForeignKeyName(), 'name', 'value');
					b()->db->createCommand()->insertAll($table, $columns, $vals);
				}
			}
		}
	}

	/**
	 * Used by CActiveRecord
	 * @return array Validation rules for model's attributes
	 */
	public function rules()
	{
		$rules = array();

		$uniques = array();
		$required = array();
		$emails = array();
		$urls = array();
		$strictLengths = array();
		$minLengths = array();
		$maxLengths = array();

		$numberTypes = array(AttributeType::TinyInt, AttributeType::SmallInt, AttributeType::MediumInt, AttributeType::Int, AttributeType::BigInt, AttributeType::Float, AttributeType::Decimal);
		$integerTypes = array(AttributeType::TinyInt, AttributeType::SmallInt, AttributeType::MediumInt, AttributeType::Int, AttributeType::BigInt);

		foreach ($this->attributes as $name => $settings)
		{
			// Catch email addresses and URLs before running normalizeAttributeSettings, since 'type' will get changed to VARCHAR
			if (isset($settings['type']) && $settings['type'] == AttributeType::Email)
				$emails[] = $name;

			if (isset($settings['type']) && $settings['type'] == AttributeType::Url)
				$urls[] = $name;

			$settings = DatabaseHelper::normalizeAttributeSettings($settings);

			// Uniques
			if (isset($settings['unique']) && $settings['unique'] === true)
				$uniques[] = $name;

			// Only enforce 'required' validation if there's no default value
			if (isset($settings['required']) && $settings['required'] === true && !isset($settings['default']))
				$required[] = $name;

			// Numbers
			if (in_array($settings['type'], $numberTypes))
			{
				$rule = array($name, 'numerical');

				if (isset($settings['min']) && is_numeric($settings['min']))
					$rule['min'] = $settings['min'];

				if (isset($settings['max']) && is_numeric($settings['max']))
					$rule['max'] = $settings['max'];

				if (in_array($settings['type'], $integerTypes))
					$rule['integerOnly'] = true;

				$rules[] = $rule;
			}

			// Enum attribute values
			if ($settings['type'] == AttributeType::Enum)
			{
				$values = ArrayHelper::stringToArray($settings['values']);
				$rules[] = array($name, 'in', 'range' => $values);
			}

			// Strict, min, and max lengths
			if (isset($settings['length']) && is_numeric($settings['length']))
				$strictLengths[(string)$settings['length']][] = $name;
			else
			{
				// Only worry about min- and max-lengths if a strict length isn't set
				if (isset($settings['minLength']) && is_numeric($settings['minLength']))
					$minLengths[(string)$settings['minLength']][] = $name;

				if (isset($settings['maxLength']) && is_numeric($settings['maxLength']))
					$maxLengths[(string)$settings['maxLength']][] = $name;
			}

			// Regex pattern matching
			if (!empty($settings['matchPattern']))
				$rules[] = array($name, 'match', 'pattern' => $settings['matchPattern']);
		}

		// Catch any unique indexes
		foreach ($this->indexes as $index)
		{
			if (isset($index['unique']) && $index['unique'] === true)
			{
				$columns = ArrayHelper::stringToArray($index['columns']);
				$initialColumn = array_shift($columns);
				$rules[] = array($initialColumn, 'Blocks\CompositeUniqueValidator', 'with' => implode(',', $columns));
			}
		}

		if ($uniques)
			$rules[] = array(implode(',', $uniques), 'unique');

		if ($required)
			$rules[] = array(implode(',', $required), 'required');

		if ($emails)
			$rules[] = array(implode(',', $emails), 'email');

		if ($urls)
			$rules[] = array(implode(',', $urls), 'Blocks\UrlValidator', 'requireSchema' => false);

		if ($strictLengths)
		{
			foreach ($strictLengths as $strictLength => $attributeNames)
			{
				$rules[] = array(implode(',', $attributeNames), 'length', 'is' => (int)$strictLength);
			}
		}

		if ($minLengths)
		{
			foreach ($minLengths as $minLength => $attributeNames)
			{
				$rules[] = array(implode(',', $attributeNames), 'length', 'min' => (int)$minLength);
			}
		}

		if ($maxLengths)
		{
			foreach ($maxLengths as $maxLength => $attributeNames)
			{
				$rules[] = array(implode(',', $attributeNames), 'length', 'max' => (int)$maxLength);
			}
		}

		$rules[] = array(implode(',', array_keys($this->attributes)), 'safe', 'on' => 'search');

		return $rules;
	}

	/**
	 * Used by CActiveRecord
	 * @return array Relational rules
	 */
	public function relations()
	{
		$relations = array();

		foreach ($this->hasMany as $key => $settings)
		{
			$relations[$key] = $this->generateHasXRelation(self::HAS_MANY, $settings);
		}

		foreach ($this->hasOne as $key => $settings)
		{
			$relations[$key] = $this->generateHasXRelation(self::HAS_ONE, $settings);
		}

		foreach ($this->belongsTo as $key => $settings)
		{
			$relations[$key] = array(self::BELONGS_TO, __NAMESPACE__.'\\'.$settings['model'], $key.'_id');
		}

		return $relations;
	}

	/**
	 * Get the records that were recently created
	 * @param int limit Number of rows to get (default is 50)
	 * @return \Blocks\BaseModel
	 */
	public function recentlyCreated($limit = 50)
	{
		$this->getDbCriteria()->mergeWith(array(
			'order' => 'date_created DESC',
			'limit' => $limit,
		));
		return $this;
	}

	/**
	 * Get the records that were recently modified
	 * @param int limit Number of rows to get (default is 50)
	 * @return \Blocks\BaseModel
	 */
	public function recentlyUpdated($limit = 50)
	{
		$this->getDbCriteria()->mergeWith(array(
			'order' => 'date_modified DESC',
			'limit' => $limit,
		));
		return $this;
	}

	/**
	 * Generates HAS_MANY and HAS_ONE relations
	 * @access protected
	 * @param string $relationType The type of relation to generate (self::HAS_MANY or self::HAS_ONE)
	 * @param array $settings The relation settings
	 * @return array The CActiveRecord relation
	 */
	protected function generateHasXRelation($relationType, $settings)
	{
		if (is_array($settings['foreignKey']))
		{
			$fk = array();
			foreach ($settings['foreignKey'] as $fk1 => $fk2)
			{
				$fk[$fk1.'_id'] = $fk2.'_id';
			}
		}
		else
		{
			$fk = $settings['foreignKey'].'_id';
		}

		$relation = array($relationType, __NAMESPACE__.'\\'.$settings['model'], $fk);

		if (isset($settings['through']))
			$relation['through'] =  __NAMESPACE__.'\\'.$settings['through'];

		return $relation;
	}

	/**
	 * Retrieves a list of models based on the current search/filter conditions.
	 * @return \CActiveDataProvider The data provider that can return the models based on the search/filter conditions.
	 */
	public function search()
	{
		// Warning: Please modify the following code to remove attributes that
		// should not be searched.

		$criteria = new \CDbCriteria;

		foreach (array_keys($this->attributes) as $attributeName)
		{
			$criteria->compare($attributeName, $this->$attributeName);
		}

		return new \CActiveDataProvider($this, array(
			'criteria' => $criteria
		));
	}

	/**
	 * Saves the record, whether it's new or existing
	 *
	 * @param bool $runValidation
	 * @param null $attributes
	 * @return bool
	 */
	function save($runValidation = true, $attributes = null)
	{
		if ($this->isNewRecord)
		   return parent::save($runValidation, $attributes);

		if (!$runValidation || $this->validate())
		{
			return $this->update($attributes);
		}

		return false;
	}

	/**
	 * Creates the model's table
	 */
	public function createTable()
	{
		$tableName = $this->getTableName();

		$indexes = array_merge($this->indexes);

		// Add any Foreign Key columns
		foreach ($this->belongsTo as $name => $settings)
		{
			$required = isset($settings['required']) ? $settings['required'] : false;
			$settings = array('type' => AttributeType::Int, 'required' => $required);
			$columns[$name.'_id'] = $settings;

			// Add unique index for this column?
			// (foreign keys already get indexed, so we're only concerned with whether it should be unique)
			if (isset($settings['unique']) && $settings['unique'] === true)
				$indexes[] = array('columns' => array($name.'_id'), 'unique' => $settings['unique']);
		}

		// Add all other columns
		foreach ($this->attributes as $name => $settings)
		{
			$settings = DatabaseHelper::normalizeAttributeSettings($settings);

			// Add (unique) index for this column?
			$unique = (isset($settings['unique']) && $settings['unique'] === true);
			if ($unique || (isset($settings['indexed']) && $settings['indexed'] === true))
				$indexes[] = array('columns' => array($name), 'unique' => $unique);

			$columns[$name] = $settings;
		}

		// Create the table
		b()->db->createCommand()->createTable($tableName, $columns);

		// Create the indexes
		$tablePrefix = b()->config->tablePrefix;
		foreach ($this->indexes as $index)
		{
			$columns = ArrayHelper::stringToArray($index['columns']);
			$unique = (isset($index['unique']) && $index['unique'] === true);
			$name = "{$tablePrefix}{$tableName}_".implode('_', $columns).($unique ? '_unique' : '').'_idx';

			b()->db->createCommand()->createIndex($name, $tableName, implode(',', $columns), $unique);
		}

		// Create the content join table if necessary
		if ($this->hasContent)
			$this->createContentJoinTable();

		// Create the content blocks join table if necessary
		if ($this->hasBlocks)
			$this->createBlocksJoinTable();

		// Create the settings table if necessary
		if ($this->hasSettings)
			$this->createSettingsTable();
	}

	/**
	 * Drops the model's table
	 */
	public function dropTable()
	{
		$table = $this->getTableName();
		if (b()->db->schema->getTable($table) !== null)
			b()->db->createCommand()->dropTable($table);

		// Drop the content join table if necessary
		if ($this->hasContent)
			$this->dropContentJoinTable();

		// Drop the content blocks join table if necessary
		if ($this->hasBlocks)
			$this->dropBlocksJoinTable();

		// Drop the settings table if necessary
		if ($this->hasSettings)
			$this->dropSettingsTable();
	}

	/**
	 * Adds foreign keys to the model's table
	 */
	public function addForeignKeys()
	{
		$tablePrefix = b()->config->tablePrefix;
		$tableName = $this->getTableName();

		foreach ($this->belongsTo as $name => $settings)
		{
			$otherModelClass = __NAMESPACE__.'\\'.$settings['model'];
			$otherModel = new $otherModelClass;
			$otherTableName = $otherModel->getTableName();
			$fkName = "{$tablePrefix}{$tableName}_{$otherTableName}_fk";
			b()->db->createCommand()->addForeignKey($fkName, $tableName, $name.'_id', $otherTableName, 'id', 'NO ACTION', 'NO ACTION');
		}
	}

	/**
	 * Drops the foreign keys from the model's table
	 */
	public function dropForeignKeys()
	{
		$tablePrefix = b()->config->tablePrefix;
		$tableName = $this->getTableName();

		foreach ($this->belongsTo as $name => $settings)
		{
			$otherModelClass = __NAMESPACE__.'\\'.$settings['model'];
			$otherModel = new $otherModelClass;
			$otherTableName = $otherModel->getTableName();
			$fkName = "{$tablePrefix}{$tableName}_{$otherTableName}_fk";
			b()->db->createCommand()->dropForeignKey($fkName, $tableName);
		}
	}

	/**
	 * Create the model's content join table
	 */
	public function createContentJoinTable()
	{
		$tablePrefix = b()->config->tablePrefix;
		$joinTable = $this->getContentJoinTableName();
		$modelTable = $this->getTableName();
		$modelFk = $this->getForeignKeyName();

		$columns = array(
			$modelFk     => array('type' => AttributeType::Int, 'required' => true),
			'content_id' => array('type' => AttributeType::Int, 'required' => true),
			'num'        => array('type' => AttributeType::Int, 'required' => true, 'unsigned' => true),
			'name'       => AttributeType::Name,
			'active'     => AttributeType::Boolean,
			'type'       => array('type' => AttributeType::Enum, 'values' => array('published','draft','autosave'), 'default' => 'draft', 'required' => true)
		);

		// Create the table
		b()->db->createCommand()->createTable($joinTable, $columns);

		// Add the foreign keys
		b()->db->createCommand()->addForeignKey("{$tablePrefix}{$joinTable}_{$modelTable}_fk", $joinTable, $modelFk,     $modelTable, 'id', 'NO ACTION', 'NO ACTION');
		b()->db->createCommand()->addForeignKey("{$tablePrefix}{$joinTable}_content_fk",       $joinTable, 'content_id', 'content',   'id', 'NO ACTION', 'NO ACTION');
	}

	/**
	 * Drop the model's content join table
	 */
	public function dropContentJoinTable()
	{
		$table = $this->getContentJoinTableName();
		if (b()->db->schema->getTable($table) !== null)
			b()->db->createCommand()->dropTable($table);
	}

	/**
	 * Create the model's content blocks join table
	 */
	public function createBlocksJoinTable()
	{
		$tablePrefix = b()->config->tablePrefix;
		$joinTable = $this->getBlocksJoinTableName();
		$modelTable = $this->getTableName();
		$modelFk = $this->getForeignKeyName();

		$columns = array(
			$modelFk     => array('type' => AttributeType::Int, 'required' => true),
			'block_id'   => array('type' => AttributeType::Int, 'required' => true),
			'required'   => AttributeType::Boolean,
			'sort_order' => AttributeType::SortOrder
		);

		// Create the table
		b()->db->createCommand()->createTable($joinTable, $columns);

		// Add the foreign keys
		b()->db->createCommand()->addForeignKey("{$tablePrefix}{$joinTable}_{$modelTable}_fk", $joinTable, $modelFk,   $modelTable, 'id', 'NO ACTION', 'NO ACTION');
		b()->db->createCommand()->addForeignKey("{$tablePrefix}{$joinTable}_blocks_fk",        $joinTable, 'block_id', 'blocks',    'id', 'NO ACTION', 'NO ACTION');
	}

	/**
	 * Drop the model's content blocks join table
	 */
	public function dropBlocksJoinTable()
	{
		$table = $this->getBlocksJoinTableName();
		if (b()->db->schema->getTable($table) !== null)
			b()->db->createCommand()->dropTable($table);
	}

	/**
	 * Create the model's settings table
	 */
	public function createSettingsTable()
	{
		$tablePrefix = b()->config->tablePrefix;
		$settingsTable = $this->getSettingsTableName();
		$modelTable = $this->getTableName();
		$modelFk = $this->getForeignKeyName();

		$columns = array(
			$modelFk => array('type' => AttributeType::Int, 'required' => true),
			'name'   => array('type' => AttributeType::Varchar, 'maxLength' => 100, 'required' => true),
			'value'  => AttributeType::Text
		);

		// Create the table
		b()->db->createCommand()->createTable($settingsTable, $columns);

		// Add the foreign key
		b()->db->createCommand()->addForeignKey("{$tablePrefix}{$settingsTable}_{$modelTable}_fk", $settingsTable, $modelFk, $modelTable, 'id', 'NO ACTION', 'NO ACTION');
	}

	/**
	 * Drop the model's settings table
	 */
	public function dropSettingsTable()
	{
		$table = $this->getSettingsTableName();
		if (b()->db->schema->getTable($table) !== null)
			b()->db->createCommand()->dropTable($table);
	}

	/**
	 * @param $id
	 * @param string $condition
	 * @param array $params
	 * @return \CActiveRecord
	 */
	public function findById($id, $condition = '', $params = array())
	{
		return $this->findByPk($id, $condition, $params);
	}

	/**
	 * Creates an active record with the given attributes.
	 * If one of the attributes is 'class', then the actual instance will be of that class
	 * @param array $attributes attribute values (column name=>column value)
	 * @param boolean $callAfterFind whether to call {@link afterFind} after the record is populated.
	 * @return CActiveRecord the newly created active record. The class of the object is the same as the model class.
	 * Null is returned if the input data is false.
	 */
	public function populateRecord($attributes, $callAfterFind=true)
	{
		if (!empty($attributes['class']))
		{
			$class = __NAMESPACE__.'\\'.$this->classPrefix.$attributes['class'].$this->classSuffix;
			if ($class != get_class($this))
				return $class::model()->populateRecord($attributes, $callAfterFind);
		}

		return parent::populateRecord($attributes, $callAfterFind);
	}

	/**
	 * Returns an instance of the specified model
	 *
	 * @static
	 * @param string $class
	 * @return object The model instance
	 */
	public static function model($class = __CLASS__)
	{
		return parent::model(get_called_class());
	}
}
