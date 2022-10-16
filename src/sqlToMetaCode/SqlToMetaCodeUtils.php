<?php

namespace SqlToCodeGenerator\sqlToMetaCode;

use SqlToCodeGenerator\codeGeneration\metadata\Bean;
use SqlToCodeGenerator\codeGeneration\metadata\BeanProperty;
use SqlToCodeGenerator\codeGeneration\metadata\BeanPropertyColKey;
use SqlToCodeGenerator\codeGeneration\metadata\BeanPropertyType;
use SqlToCodeGenerator\codeGeneration\metadata\Enum;
use SqlToCodeGenerator\codeGeneration\metadata\ForeignBean;
use SqlToCodeGenerator\sql\SqlDao;
use SqlToCodeGenerator\sqlToMetaCode\bean\Column;
use SqlToCodeGenerator\sqlToMetaCode\bean\KeyColumnUsage;
use SqlToCodeGenerator\sqlToMetaCode\bean\Table;

abstract class SqlToMetaCodeUtils {

	private final function __construct() {}

	/**
	 * @param Table[] $tables
	 * @param Column[] $columns
	 * @param KeyColumnUsage[] $keyColumnUsages
	 * @return Bean[]
	 */
	public static function getBeansFromMetaCodeBeans(
			array $tables,
			array $columns,
			array $keyColumnUsages
	): array {
		/** @var Table[] $tablesByTableName */
		$tablesByTableName = array();
		foreach ($tables as $table) {
			$tablesByTableName[$table->tableName] = $table;
		}
		/** @var KeyColumnUsage[][] $keyColumnUsageListsByTableName */
		$keyColumnUsageListsByTableName = array();
		foreach ($keyColumnUsages as $keyColumnUsage) {
			$keyColumnUsageListsByTableName[$keyColumnUsage->tableName][] = $keyColumnUsage;
		}

		$beansBySqlTable = array();
		foreach ($columns as $column) {
			$table = $tablesByTableName[$column->tableName];
			$keyColumnUsages = $keyColumnUsageListsByTableName[$column->tableName] ?? array();

			$bean = new Bean();
			$bean->sqlTable = $table->tableName;
			foreach ($keyColumnUsages as $keyColumnUsage) {
				$bean->colNamesByUniqueConstraintName[$keyColumnUsage->constraintName]
						= $keyColumnUsage->columnName;
			}
			$bean = $beansBySqlTable[$bean->sqlTable] ?? $bean;
			$beansBySqlTable[$bean->sqlTable] = $bean;

			$property = new BeanProperty();

			$property->sqlName = $column->columnName;
			$property->sqlComment = $column->columnComment;
			$property->belongsToBean = $bean;
			$property = $beanPropertiesByUniqueKey[$property->getUniqueKey()] ?? $property;
			$beanPropertiesByUniqueKey[$property->getUniqueKey()] = $property;

			$property->isNullable = $column->isNullable === 'YES';
			$property->propertyType = BeanPropertyType::getPropertyTypeFromSql($column->dataType, $column->columnType);
			$property->columnKey = BeanPropertyColKey::getFromString($column->columnKey);
			$defaultValue = $column->columnDefault;
			$defaultValue = $defaultValue === 'NULL' ? 'null' : $defaultValue;

			switch ($property->propertyType) {
				case BeanPropertyType::INT:
				case BeanPropertyType::FLOAT:
				case BeanPropertyType::STRING:
					if ($defaultValue !== null) {
						$property->defaultValueAsString = $defaultValue;
					}
					break;
				case BeanPropertyType::DATE:
					if ($defaultValue === 'current_timestamp()') {
						// This default value is not handled by PHP for now
						// $property->defaultValueAsString = "new \DateTime()";
					}
					break;
				case BeanPropertyType::ENUM:
					$enum = new Enum();
					$enum->sqlComment = $column->columnComment;
					$enum->name = SqlDao::sqlToCamelCase("{$bean->sqlTable}_{$property->sqlName}_enum");
					$colType = $column->columnType;
					$re = '/\'([^\']+)\'/m';
					preg_match_all($re, $colType, $matches);

					$enum->values = $matches[1];

					$property->enum = $enum;
					if ($defaultValue !== null) {
						$property->defaultValueAsString = $defaultValue;
					}

					break;
				case BeanPropertyType::BOOL:
					$property->defaultValueAsString =  match ($defaultValue) {
						'0' => 'false',
						'1' => 'true',
						null => $property->isNullable ? 'null' : null,
					};
					break;
				case BeanPropertyType::JSON:
				case BeanPropertyType::OBJECT:
					break;
			}

			foreach ($keyColumnUsages as $keyColumnUsage) {
				if (
						$keyColumnUsage->tableSchema === $column->tableSchema
						&&  $keyColumnUsage->tableName == $column->tableName
						&&  $keyColumnUsage->columnName == $column->columnName
				) {
					$fkBean = new ForeignBean();
					$bean->foreignBeans[] = $fkBean;

					$fkBean->toBean = new Bean();
					$fkBean->toBean->sqlTable = $keyColumnUsage->referencedTableName;
					$fkBean->toBean->colNamesByUniqueConstraintName
							= $colNamesByUniqueConstraintNameByTableName[$keyColumnUsage->referencedTableName] ?? [];
					$fkBean->toBean = $beansBySqlTable[$fkBean->toBean->sqlTable] ?? $fkBean->toBean;
					$beansBySqlTable[$fkBean->toBean->sqlTable] = $fkBean->toBean;

					$fkBean->withProperty = $property;

					$fkBean->onProperty = new BeanProperty();
					$fkBean->onProperty->sqlName = $keyColumnUsage->referencedColumnName;
					$fkBean->onProperty->belongsToBean = $fkBean->toBean;
					$fkBean->onProperty = $beanPropertiesByUniqueKey[$fkBean->onProperty->getUniqueKey()] ?? $fkBean->onProperty;
					$beanPropertiesByUniqueKey[$fkBean->onProperty->getUniqueKey()] = $fkBean->onProperty;

					$reverseFkBean = new ForeignBean();
					$reverseFkBean->isArray = true;
					$reverseFkBean->toBean = $bean;
					$reverseFkBean->onProperty = $fkBean->withProperty;
					$reverseFkBean->withProperty = $fkBean->onProperty;
					$fkBean->toBean->foreignBeans[] = $reverseFkBean;
				}

				$property = $beanPropertiesByUniqueKey[$property->getUniqueKey()] ?? $property;
				$beanPropertiesByUniqueKey[$property->getUniqueKey()] = $property;

				$bean->properties[] = $property;
			}
		}

		return $beansBySqlTable;
	}

}