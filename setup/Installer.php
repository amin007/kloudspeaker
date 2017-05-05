<?php

namespace Kloudspeaker\Setup;

class Installer {
	private $versions = NULL;

	public function __construct($c) {
		$this->container = $c;
		$this->logger = $c->logger;
	}

	public function initialize() {
		$this->container->commands->register('installer:check', [$this, 'checkInstallation']);
		$this->container->commands->register('installer:perform', [$this, 'performInstallation']);
	}

	public function checkInstallation() {
		$this->logger->info("Checking installation");

		$result = [
			"system" => [
				"configuration" => FALSE,
				"database_configuration" => FALSE,
				"database_connection" => FALSE,
				"database_version" => NULL,
				"installation" => FALSE
			],
			"plugins" => [],
			"available" => []
		];

		$this->logger->info("Checking configuration...");
		if (!$this->container->configuration->getSystemInfo()["config_exists"]) {
			$this->logger->error("Kloudspeaker not configured");
			return $result;
		}
		$result["system"]["configuration"] = TRUE;

		$this->logger->info("Checking database configuration...");
		if (!$this->isDatabaseConfigured()) {
			$this->logger->error("Database not configured");
			return $result;
		}
		$result["system"]["database_configuration"] = TRUE;

		$this->logger->info("Checking database connection...");
		$conn = $this->container->dbfactory->checkConnection();
		if (!$conn["connection"]) {
		    $this->logger->error("Cannot connect to database: ".$conn["reason"]);
		    $result["system"]["database_connection_error"] = $conn["reason"];
		    return $result;
		}
		$result["system"]["database_connection"] = TRUE;

		$db = $this->container->db;

		$this->logger->info("Checking database tables...");
		if ($db->tableExists("parameter")) {
			$this->logger->info("Old installation exists, checking database version");
			$result["system"]["installation"] = TRUE;

			$latestVersion = $this->getLatestVersion();

			try {
				$installedVersion = $this->getSystemInstalledVersion();

				if (!$installedVersion or $installedVersion == NULL) {
					$this->logger->info("No database version found");
					
					$migrateFromVersion = $db->select('parameter', ['name', 'value'])->where('name', 'version')->done()->execute()->firstValue('value');

					if (!$migrateFromVersion or $migrateFromVersion == NULL) {
						$this->logger->info("Installation exists but no migration version found");
						return $result;
					}

					$this->logger->info("Old version detected: $migrateFromVersion");
					if ($migrateFromVersion != "2_7_28") {
						$this->logger->error("Cannot migrate from this version, update to last 2.x version");
						return $result;
					}
					$result["available"][] = ["id" => "system:migrate", "from" => $migrateFromVersion, "to" => $latestVersion];

					//TODO check plugins
					return $result;
				}

				$this->logger->info("Installed version: $installedVersion");

				$latest = ($installedVersion == $latestVersion["id"]);
				if (!$latest) {
					$result["available"][] = ["id" => "system:update", "from" => $installedVersion, "to" => $latestVersion];
				}

				//TODO check plugins

				return $result;
			} catch (Exception $e) {
				$this->logger->info("Unable to resolve installed version: ".$e->getMessage());
				return $result;
			}
		} else {
			// no table exist, assume empty database
			$result["available"][] = ["id" => "system:install", "to" => $this->getLatestVersion()];
		}

		return $result;
	}

	public function getSystemInstalledVersion() {
		return $this->container->db->select('parameter', ['name', 'value'])->where('name', 'database')->done()->execute()->firstValue('value');
	}

	public function getPluginInstalledVersion($id) {
		return $this->container->db->select('parameter', ['name', 'value'])->where('name', "plugin_$id_version")->done()->execute()->firstValue('value');
	}

	public function getVersionInfo() {
		if ($this->versions == NULL)
			$this->versions = json_decode($this->readFile('/setup/db/versions.json'), TRUE);
		return $this->versions;
	}

	public function getLatestVersion() {
		$ver = $this->getVersionInfo();	//make sure versions are read
		if (count($ver["versions"]) == 0) throw new \Kloudspeaker\KloudspeakerException("No version info found");
		return $ver["versions"][count($ver["versions"])-1];
	}

	private function getAllInstallablePlugins() {
		$result = [];
		foreach ($this->container->plugins->get() as $p) {
			/*if ($p->version() == NULL) {
				continue;
			}
			$ps = $settings[$id];
			$custom = (isset($ps["custom"]) and $ps["custom"] == TRUE);

			$util->execPluginCreateTables($id, ($custom ? $customPath : NULL));*/
		}
		return $result;
	}

	public function performInstallation() {
		$check = $this->checkInstallation();

		if (count($check["available"]) == 0) {
			return [
				"check" => $check,
				"reason" => "No actions available",
				"success" => FALSE
			];
		}
		$this->logger->info("Performing installation");

		$this->container->db->startTransaction();
		$result = [];
		try {
			foreach ($check["available"] as $action) {
				$actionResult = [];
				if ($action["id"] == "system:install") $actionResult = $this->installSystem();
				else if ($action["id"] == "system:migrate") $actionResult = $this->migrateSystem();
				else throw new \Kloudspeaker\KloudspeakerException("Invalid install action: ".$action["id"]);
			}
			$this->container->db->commit();
		} catch (Exception $e) {
			$this->container->db->rollback();
		}
		$result["success"] = TRUE;
		return $result;
	}

	private function installSystem() {
		$db = $this->container->db;
		$db->script($this->readFile('/setup/db/create.sql'));

		// add version info
		$latestVersion = $this->getLatestVersion();
		$db->insert('parameter', ['name' => 'database', 'value' => $latestVersion["id"]])->execute();

		// install all plugins
		foreach ($this->getAllInstallablePlugins() as $plugin) {
			# code...
		}
	}

	private function updateSystem() {

	}

	private function migrateSystem() {
		$db = $this->container->db;

		$fromVersion = $db->select('parameter', ['name', 'value'])->where('name', 'version')->done()->execute()->firstValue('value');

		if (!$fromVersion or $fromVersion == NULL) {
			$this->logger->info("No migration version found");
			return FALSE;
		}

		$this->logger->info("Migrating from version: $fromVersion");
		if ($fromVersion != "2_7_28") {
			$this->logger->info("Cannot migrate from this version, update to last 2.x version");
			return FALSE;
		}

		//TODO run migration job

		return TRUE;
	}

	private function readFile($path) {
		return file_get_contents($this->container->configuration->getInstallationRoot() . $path);
	}

	public function isDatabaseConfigured() {
		$c = $this->container->configuration;
		if (!$c->has("db")) return FALSE;
		$dbc = $c->get("db");
		if (!array_key_exists("dsn", $dbc)) return FALSE;
		if (!array_key_exists("user", $dbc)) return FALSE;
		if (!array_key_exists("password", $dbc)) return FALSE;
		return TRUE;
	}
}