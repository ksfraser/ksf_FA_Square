# AGENTS.md Addendum: Inter-Module Communication Pattern

## Overview

This document describes a standardized pattern for ksf modules to discover and communicate with each other using FrontAccounting's built-in `hook_invoke` function.

This is the native FA mechanism for inter-module communication, similar to how `db_prevoid`, `db_presave`, and `db_postsave` work for transaction hooks.

---

## The Problem

Previously, ksf modules used various ad-hoc methods to detect each other:
1. **Checking hardcoded file paths** (e.g., `/tmp/ksf_generate/`) - Only works in dev environments
2. **Assuming constants are defined** - Only works if the module's entry point is loaded before yours
3. **No standardized way** to discover what capabilities other modules provide

These approaches are fragile and don't work reliably across dev and prod environments.

---

## The Solution: `hook_invoke` Pattern

FrontAccounting provides `hook_invoke()` specifically for calling methods on other modules' hook classes:

```php
/**
 * Calls hook $method defined in extension $ext (if any)
 */
function hook_invoke($ext, $method, &$data, $opts=null)
{
    global $Hooks;
    $ret = null;
    if (isset($Hooks[$ext]) && method_exists($Hooks[$ext], $method)) {
        set_ext_domain('modules/'.$ext);
        $ret = $Hooks[$ext]->$method($data, $opts);
        set_ext_domain();
    }
    return $ret;
}
```

**Key features**:
1. Uses the `$Hooks` global that FA already maintains for all active modules
2. Passes data by reference for flexibility
3. Supports additional options via `$opts`
4. Handles extension domain switching automatically

---

## Standardized Methods

All ksf modules should implement these 4 methods in their hooks class:

### 1. `getModuleConstants(&$data, $opts = null)`

Returns all constants defined by this module that other modules might need.

```php
public function getModuleConstants(&$data, $opts = null) {
    $constants = [
        'KSF_YOURMODULE_PREFS' => KSF_YOURMODULE_PREFS,
        'KSF_YOURMODULE_VERSION' => '1.0.0',
        // Add other relevant constants
    ];
    
    // Return via both return value and reference for flexibility
    $data['constants'] = $constants;
    return $constants;
}
```

**How to call from another module**:
```php
$data = [];
$constants = hook_invoke('ksf_generate', 'getModuleConstants', $data);

if ($constants !== null && isset($constants['KSF_GENERATE_CATALOGUE_PREFS'])) {
    $prefsTable = $constants['KSF_GENERATE_CATALOGUE_PREFS'];
    // Use the table name...
}
```

---

### 2. `getModuleCapabilities(&$data, $opts = null)`

Returns all capabilities provided by this module with descriptions.

```php
public function getModuleCapabilities(&$data, $opts = null) {
    $capabilities = [
        'export' => [
            'description' => 'Export products from FrontAccounting to Square',
            'methods' => ['exportProducts', 'syncInventory'],
        ],
        'import' => [
            'description' => 'Import orders from Square to FrontAccounting',
            'methods' => ['importOrders'],
        ],
        // Add your module's capabilities
    ];
    
    $data['capabilities'] = $capabilities;
    return $capabilities;
}
```

**How to call from another module**:
```php
$data = [];
$capabilities = hook_invoke('ksf_FA_Square', 'getModuleCapabilities', $data);

if ($capabilities !== null && isset($capabilities['export'])) {
    $description = $capabilities['export']['description'];
    $methods = $capabilities['export']['methods'];
    // Module has export capability...
}
```

---

### 3. `hasCapability(&$data, $opts = null)`

Checks if this module provides a specific capability.

```php
public function hasCapability(&$data, $opts = null) {
    // Get capability from either $opts or $data (flexibility)
    $capability = $opts['capability'] ?? $data['capability'] ?? null;

    if ($capability === null) {
        $data['has_capability'] = false;
        $data['error'] = 'No capability specified';
        return false;
    }

    // List all capabilities this module provides
    $capabilities = ['export', 'import', 'payments', 'config'];
    $hasCapability = in_array($capability, $capabilities);

    // Return via both return value and reference
    $data['has_capability'] = $hasCapability;
    $data['capability_checked'] = $capability;

    return $hasCapability;
}
```

**How to call from another module**:
```php
// Option 1: Pass via $opts
$data = [];
$hasExport = hook_invoke('ksf_FA_Square', 'hasCapability', $data, ['capability' => 'export']);

// Option 2: Pass via $data
$data2 = ['capability' => 'import'];
$hasImport = hook_invoke('ksf_FA_Square', 'hasCapability', $data2);

if ($hasExport) {
    // Module has export capability...
}
```

---

### 4. `respondToCapabilityRequest(&$data, $opts = null)`

A generic responder that handles multiple request types. This is useful as a single entry point.

```php
public function respondToCapabilityRequest(&$data, $opts = null) {
    // Get request type from either $opts or $data
    $request = $opts['request'] ?? $data['request'] ?? 'capabilities';

    $data['request'] = $request;
    $data['module'] = $this->module_name;

    switch ($request) {
        case 'capabilities':
            return $this->getModuleCapabilities($data, $opts);

        case 'constants':
            return $this->getModuleConstants($data, $opts);

        case (strpos($request, 'has:') === 0):
            $capability = substr($request, 4);
            return $this->hasCapability($data, ['capability' => $capability]);

        default:
            $data['error'] = 'Unknown request type: ' . $request;
            return null;
    }
}
```

**How to call from another module**:
```php
// Get constants
$data = [];
$constants = hook_invoke('ksf_FA_Square', 'respondToCapabilityRequest', $data, ['request' => 'constants']);

// Get capabilities
$data2 = [];
$capabilities = hook_invoke('ksf_FA_Square', 'respondToCapabilityRequest', $data2, ['request' => 'capabilities']);

// Check specific capability
$data3 = [];
$hasExport = hook_invoke('ksf_FA_Square', 'respondToCapabilityRequest', $data3, ['request' => 'has:export']);
```

---

## Multi-layered Discovery Strategy

When discovering other modules, use a multi-layered approach for maximum compatibility:

```php
/**
 * Tries to discover a ksf module using multiple methods.
 * 
 * @param string $tablePrefix Database table prefix
 * @param array $moduleNames List of possible module names to try
 * @param string|null $constantName Optional constant to check for
 * @param string|null $tableName Optional table to check for
 * @param array $paths Optional file paths to check
 * @return array|null Discovered info or null
 */
function discoverKsfModule(
    string $tablePrefix,
    array $moduleNames,
    ?string $constantName = null,
    ?string $tableName = null,
    array $paths = []
): ?array {
    global $Hooks;

    // ========================================
    // LAYER 1: hook_invoke (PREFERRED METHOD)
    // ========================================
    foreach ($moduleNames as $moduleName) {
        if (isset($Hooks[$moduleName])) {
            // Try getModuleConstants first
            $data = [];
            $constants = hook_invoke($moduleName, 'getModuleConstants', $data);
            
            if ($constants !== null) {
                // Check for specific constant if requested
                if ($constantName !== null && isset($constants[$constantName])) {
                    return [
                        'installed' => true,
                        'module_name' => $moduleName,
                        'via_hooks' => true,
                        'constant_value' => $constants[$constantName],
                        'constants' => $constants,
                    ];
                }
                
                // If no specific constant requested, just having the hooks is enough
                if ($constantName === null) {
                    return [
                        'installed' => true,
                        'module_name' => $moduleName,
                        'via_hooks' => true,
                        'constants' => $constants,
                    ];
                }
            }

            // Also try the generic responder
            $data2 = [];
            $response = hook_invoke($moduleName, 'respondToCapabilityRequest', $data2, ['request' => 'constants']);
            
            if ($response !== null && is_array($response)) {
                if ($constantName !== null && isset($response[$constantName])) {
                    return [
                        'installed' => true,
                        'module_name' => $moduleName,
                        'via_hooks' => true,
                        'constant_value' => $response[$constantName],
                        'constants' => $response,
                    ];
                }
            }
        }
    }

    // ========================================
    // LAYER 2: Constant check
    // ========================================
    if ($constantName !== null && defined($constantName)) {
        return [
            'installed' => true,
            'via_constant' => true,
            'constant_value' => constant($constantName),
        ];
    }

    // ========================================
    // LAYER 3: Database table check (most reliable if table exists)
    // ========================================
    if ($tableName !== null) {
        $checkTable = db_query("SHOW TABLES LIKE '{$tablePrefix}{$tableName}'");
        if ($checkTable !== false && db_num_rows($checkTable) > 0) {
            return [
                'installed' => true,
                'via_table' => true,
                'table_name' => $tableName,
            ];
        }
    }

    // ========================================
    // LAYER 4: File system check (for dev environments)
    // ========================================
    foreach ($paths as $path) {
        if (@is_dir($path) || @file_exists($path)) {
            return [
                'installed' => true,
                'via_filesystem' => true,
                'path' => $path,
            ];
        }
    }

    return null;
}
```

**Example usage for ksf_generate_catalogue**:
```php
$discovered = discoverKsfModule(
    $tablePrefix,
    ['ksf_generate', 'ksf_generate_catalogue', 'ksf_gen_catalogue'],
    'KSF_GENERATE_CATALOGUE_PREFS',
    'ksf_gen_catalogue_prefs',
    [
        '/tmp/ksf_generate/',
        dirname(__DIR__, 4) . '/modules/ksf_generate/',
        dirname(__DIR__, 3) . '/modules/ksf_generate/',
    ]
);

if ($discovered !== null && $discovered['installed']) {
    // Module is installed!
    $prefsTable = $discovered['constant_value'] ?? 'ksf_gen_catalogue_prefs';
    // Load preferences...
}
```

---

## Complete Hooks Class Template

Here's a complete template you can copy-paste into your module's hooks.php:

```php
<?php
/**
 * Your Module Hooks
 */
define ('SS_ksf_yourmodule', 108<<8);

/**
 * Constants defined by this module for inter-module communication
 */
define('KSF_YOURMODULE_MODULE_NAME', 'ksf_yourmodule');
define('KSF_YOURMODULE_CAPABILITIES', 'capability1,capability2');
// define('KSF_YOURMODULE_PREFS', 'your_prefs_table'); // If you have one

class hooks_ksf_yourmodule extends hooks {

	function __construct() {
		$this->module_name = 'ksf_yourmodule';
	}

	// =========================================================================
	// INTER-MODULE COMMUNICATION METHODS
	// These allow other ksf modules to discover your module's capabilities
	// using FrontAccounting's built-in hook_invoke function.
	// =========================================================================

	/**
	 * Gets all constants defined by this module.
	 * 
	 * Call from other modules:
	 * hook_invoke('ksf_yourmodule', 'getModuleConstants', $data)
	 */
	public function getModuleConstants(&$data, $opts = null) {
		$constants = [
			'KSF_YOURMODULE_MODULE_NAME' => KSF_YOURMODULE_MODULE_NAME,
			'KSF_YOURMODULE_CAPABILITIES' => KSF_YOURMODULE_CAPABILITIES,
			// 'KSF_YOURMODULE_PREFS' => KSF_YOURMODULE_PREFS, // Uncomment if you have this
			// Add other constants here
		];

		$data['constants'] = $constants;
		return $constants;
	}

	/**
	 * Gets all capabilities provided by this module.
	 * 
	 * Call from other modules:
	 * hook_invoke('ksf_yourmodule', 'getModuleCapabilities', $data)
	 */
	public function getModuleCapabilities(&$data, $opts = null) {
		$capabilities = [
			// 'capability1' => [
			//     'description' => 'What this capability does',
			//     'methods' => ['method1', 'method2'],
			// ],
			// 'capability2' => [
			//     'description' => 'Another capability',
			//     'methods' => ['method3'],
			// ],
		];

		$data['capabilities'] = $capabilities;
		return $capabilities;
	}

	/**
	 * Checks if this module provides a specific capability.
	 * 
	 * Call from other modules:
	 * hook_invoke('ksf_yourmodule', 'hasCapability', $data, ['capability' => 'capability1'])
	 */
	public function hasCapability(&$data, $opts = null) {
		$capability = $opts['capability'] ?? $data['capability'] ?? null;

		if ($capability === null) {
			$data['has_capability'] = false;
			$data['error'] = 'No capability specified';
			return false;
		}

		$capabilities = []; // List your capability names here
		$hasCapability = in_array($capability, $capabilities);

		$data['has_capability'] = $hasCapability;
		$data['capability_checked'] = $capability;

		return $hasCapability;
	}

	/**
	 * Generic responder for capability requests.
	 * 
	 * Call from other modules:
	 * hook_invoke('ksf_yourmodule', 'respondToCapabilityRequest', $data, ['request' => 'constants|capabilities|has:capability1'])
	 */
	public function respondToCapabilityRequest(&$data, $opts = null) {
		$request = $opts['request'] ?? $data['request'] ?? 'capabilities';

		$data['request'] = $request;
		$data['module'] = $this->module_name;

		switch ($request) {
			case 'capabilities':
				return $this->getModuleCapabilities($data, $opts);

			case 'constants':
				return $this->getModuleConstants($data, $opts);

			case (strpos($request, 'has:') === 0):
				$capability = substr($request, 4);
				return $this->hasCapability($data, ['capability' => $capability]);

			default:
				$data['error'] = 'Unknown request type: ' . $request;
				return null;
		}
	}

	// ... rest of your existing hooks methods ...
}
```

---

## Benefits of This Pattern

1. **Uses FA's Native Mechanism**: `hook_invoke` is how FA is designed for modules to communicate
2. **Works in Both Dev and Prod**: No more hardcoded `/tmp/` paths
3. **Backward Compatible**: The multi-layered strategy works with modules that haven't adopted this pattern yet
4. **Self-Documenting**: The capability system describes what each module provides
5. **Extensible**: Easy to add new request types to `respondToCapabilityRequest`

---

## Modules That Have Adopted This Pattern

- `ksf_FA_Square` - Implemented in version 2.4.3+

---

## Version History

| Version | Date | Author | Changes |
|---------|------|--------|---------|
| 0.1 | 2026-05-22 | KSFraser | Initial pattern specification based on hook_invoke |
