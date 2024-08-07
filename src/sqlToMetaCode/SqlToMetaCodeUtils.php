<?php

namespace SqlToCodeGenerator\sqlToMetaCode;

use LogicException;
use SqlToCodeGenerator\codeGeneration\metadata\Bean;
use SqlToCodeGenerator\codeGeneration\metadata\BeanProperty;
use SqlToCodeGenerator\codeGeneration\metadata\BeanPropertyColKey;
use SqlToCodeGenerator\codeGeneration\metadata\BeanPropertyType;
use SqlToCodeGenerator\codeGeneration\metadata\Enum;
use SqlToCodeGenerator\codeGeneration\metadata\ForeignBeanField;
use SqlToCodeGenerator\codeGeneration\utils\VariableUtils;
use SqlToCodeGenerator\sql\SqlDao;
use SqlToCodeGenerator\sqlToMetaCode\bean\Column;
use SqlToCodeGenerator\sqlToMetaCode\bean\KeyColumnUsage;
use SqlToCodeGenerator\sqlToMetaCode\bean\Table;

final class SqlToMetaCodeUtils {

	/**
	 * @param Table[] $tables
	 * @param Column[] $columns
	 * @param KeyColumnUsage[] $keyColumnUsages
	 * @return Bean[]
	 */
	public static function getBeansFromMetaCodeBeans(
			array $tables,
			array $columns,
			array $keyColumnUsages,
	): array {
		/** @var Table[] $tablesByUniqueIdentifier */
		$tablesByUniqueIdentifier = [];
		foreach ($tables as $table) {
			$tablesByUniqueIdentifier[$table->getUniqueIdentifier()] = $table;
		}
		/** @var KeyColumnUsage[][] $keyColumnUsageListsByTableUniqueIdentifier */
		$keyColumnUsageListsByTableUniqueIdentifier = [];
		foreach ($keyColumnUsages as $keyColumnUsage) {
			$keyColumnUsageListsByTableUniqueIdentifier[$keyColumnUsage->getFromTableUniqueIdentifier()][] = $keyColumnUsage;
		}

		// Convert $columns to beans
		$beansByUniqueIdentifier = [];
		$beanPropertiesByUniqueKey = [];
		foreach ($columns as $column) {
			$table = $tablesByUniqueIdentifier[$column->getTableUniqueIdentifier()];

			$bean = new Bean();
			$bean->sqlDatabase = $table->tableSchema;
			$bean->sqlTable = $table->tableName;
			$bean = $beansByUniqueIdentifier[$bean->getUniqueIdentifier()] ?? $bean;
			$beansByUniqueIdentifier[$bean->getUniqueIdentifier()] = $bean;

			$property = new BeanProperty();
			$bean->properties[] = $property;
			$property->sqlName = $column->columnName;
			$property->sqlComment = $column->columnComment;
			$property->belongsToBean = $bean;
			$property = $beanPropertiesByUniqueKey[$property->getUniqueKey()] ?? $property;
			$beanPropertiesByUniqueKey[$property->getUniqueKey()] = $property;

			$property->isNullable = $column->isNullable();
			$property->isGenerated = $column->isGenerated();
			$property->propertyType = BeanPropertyType::getPropertyTypeFromSql($column->dataType, $column->columnType);
			$property->columnKey = BeanPropertyColKey::tryFrom($column->columnKey);
			$defaultValue = $column->columnDefault;
			$defaultValue = $defaultValue === 'NULL' ? 'null' : $defaultValue;

			switch ($property->propertyType) {
				case BeanPropertyType::INT:
				case BeanPropertyType::FLOAT:
				case BeanPropertyType::STRING:
				case BeanPropertyType::JSON:
					if ($defaultValue !== null) {
						$property->defaultValueAsString = $defaultValue;
					}
					break;
				case BeanPropertyType::DATE:
					// This default value as new DateTime is not handled by PHP for now
					if ($defaultValue !== null && !str_starts_with($defaultValue, 'current_timestamp(')) {
						$property->defaultValueAsString = $defaultValue;
					}
					break;
				case BeanPropertyType::BOOL:
					$property->defaultValueAsString = match ($defaultValue) {
						'0' => 'false',
						'1' => 'true',
						null => $property->isNullable ? 'null' : null,
					};
					break;
				case BeanPropertyType::ENUM:
				case BeanPropertyType::ENUM_LIST:
					$enum = new Enum();
					$enum->sqlComment = $column->columnComment;
					$enum->name = SqlDao::sqlToCamelCase("{$bean->sqlTable}_{$property->sqlName}_enum");
					$colType = $column->columnType;
					if ($colType === '') {
						throw new LogicException('An enum  type must have value ($colType)');
					}
					$re = '/\'([^\']+)\'/m';
					preg_match_all($re, $colType, $matches);

					$enum->values = array_map(
							array: $matches[1],
							callback: static fn(string $enumValue): string => VariableUtils::stringToEnumCompliantValue($enumValue),
					);

					$property->enum = $enum;
					if ($defaultValue !== null) {
						$property->defaultValueAsString = $defaultValue;
					}

					break;
				case BeanPropertyType::OBJECT:
					break;
			}
		}

		foreach ($beansByUniqueIdentifier as $bean) {
			$tableKeyColumnUsages = $keyColumnUsageListsByTableUniqueIdentifier[$bean->getUniqueIdentifier()] ?? [];

			foreach ($tableKeyColumnUsages as $keyColumnUsage) {
				$matchingProperty = null;
				foreach ($bean->properties as $property) {
					if ($keyColumnUsage->columnName === $property->sqlName) {
						$matchingProperty = $property;
						break;
					}
				}
				if ($matchingProperty === null) {
					throw new LogicException('Key column usage on unknown property: ' . $keyColumnUsage->constraintName);
				}

				// Do not put foreign keys
				if (
						$keyColumnUsage->referencedTableSchema === null
						&& $matchingProperty->columnKey !== BeanPropertyColKey::PRI
				) {
					$bean->colNamesByUniqueConstraintName[$keyColumnUsage->constraintName][]
							= $keyColumnUsage->columnName;
				}
			}
		}

		// Build link between beans
		foreach ($keyColumnUsages as $keyColumnUsage) {
			if ($keyColumnUsage->referencedTableSchema === null) {
				continue;
			}
			$bean = $beansByUniqueIdentifier[$keyColumnUsage->getFromTableUniqueIdentifier()];
			$property = $beanPropertiesByUniqueKey[$keyColumnUsage->getFromColumnUniqueIdentifier()];

			$onBean = $beansByUniqueIdentifier[$keyColumnUsage->getToTableUniqueIdentifier()];
			$onProperty = $beanPropertiesByUniqueKey[$keyColumnUsage->getToColumnUniqueIdentifier()];

			$fkBean = new ForeignBeanField();
			$bean->foreignBeanFields[] = $fkBean;

			$fkBean->toBean = $onBean;
			$fkBean->withProperty = $property;
			$fkBean->onProperty = $onProperty;

			$reverseFkBean = new ForeignBeanField();
			$reverseFkBean->isArray = true;
			$reverseFkBean->toBean = $bean;
			$reverseFkBean->onProperty = $fkBean->withProperty;
			$reverseFkBean->withProperty = $fkBean->onProperty;
			$fkBean->toBean->foreignBeanFields[] = $reverseFkBean;
		}

		return array_values($beansByUniqueIdentifier);
	}

}
