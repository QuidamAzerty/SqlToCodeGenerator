<?php

namespace SqlToCodeGenerator\sql;

use LogicException;

abstract class SqlUtils {

	private static PdoContainer $pdoContainer;

	final private function __construct() {}

	public static function initFromScratch(
			string $dbName,
			string $host,
			string $port,
			string $user,
			string $password,
			int $waitTimeout = 60,
	): void {
		self::$pdoContainer = new PdoContainer($dbName, $host, $port, $user, $password, $waitTimeout);
	}

	public static function initFromPdoContainer(PdoContainer $pdoContainer): void {
		self::$pdoContainer = $pdoContainer;
	}

	public static function getPdoContainer(): PdoContainer {
		if (!isset(self::$pdoContainer)) {
			throw new LogicException('PDO is null. Call an init method method first');
		}

		return self::$pdoContainer;
	}

}
