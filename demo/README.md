# GSD Interactive Demo - User Guide
**Version:** 2.0 (Modernized)
**Date:** 2025-10-18
**Architecture:** Bayesian-Optimal with Modern Facades

---

## Overview

The GSD Interactive Demo showcases the **Geodineum Service Discovery** system through an interactive web interface. This modernized version (v2.0) uses elegant facade patterns, enhanced error handling, and Bayesian-optimal architecture for minimal cognitive load and maximal flexibility.

### What's New in v2.0 (Modernized)

✅ **Facade Pattern**: TemplateManager and FormatManager for cleaner API
✅ **Status Dashboard**: Real-time daemon monitoring with connection pool stats
✅ **Enhanced Error Handling**: Specific exception types with actionable guidance
✅ **Health Stream Integration**: Load-aware service selection with live metrics
✅ **Better UX**: Progress indicators, detailed summaries, user-friendly messages

---

## Quick Start

### Step 1: Start GSD Daemon

```bash
cd ~/gh/GSD
./scripts/start-daemon.sh

# Verify daemon is running
ps aux | grep gsd-daemon
```

### Step 2: Load Demo Data

```bash
cd ~/gh/GSD-Client/demo

# Load all data at once (recommended)
php load_all_demo_data.php

# OR load selectively:
php load_demo_data.php           # Formats + basic services
php load_demo_templates.php      # Service configuration templates
php register_example_data.php    # Tera HTML templates
```

### Step 3: Start Web Server

```bash
cd ~/gh/GSD-Client/demo
php -S localhost:8080
```

### Step 4: Open Browser

Navigate to: **http://localhost:8080**

You should see:
- ✅ "Daemon Online" status
- 📊 Service/template counts
- 🔍 Status Dashboard tab (NEW!)
- Multiple interactive tabs

---

## Features Guide

### 1. Status Dashboard (NEW in v2.0)

**Location**: Status Dashboard tab

**What It Shows**:
- **Daemon Version**: Current GSD daemon version
- **Uptime**: How long daemon has been running (seconds)
- **Supported Commands**: Total available commands (50)
- **ValKey Functions**: Server-side functions loaded (127)
- **Connection Pool**: Total/idle/active connections + utilization%

**How to Use**:
1. Click "Status Dashboard" tab
2. Click "Refresh Status" button
3. View real-time metrics

**Health Update Demo**:
Test the health stream by sending sample metrics:
1. Enter service ID (e.g., `demo-service`)
2. Enter load factor (0.0-1.0, e.g., `0.42`)
3. Click "Send Health Update"
4. View response with message ID

**Technical Details**:
- Uses `Client->status('full')` facade method
- Displays r2d2 connection pool statistics
- Shows all 50 commands and 127 ValKey functions
- Health updates → `{site}:gsd:health:{node}` stream

---

### 2. Format System

**Location**: Format System tab

**Capabilities**:
- **List Formats**: View all registered message formats
- **Detect Format**: Auto-detect format from message content
- **Convert Format**: Transform between formats (JSON v1 ↔ v2 ↔ Compact)

**Modern API Pattern**:
```php
$fm = $client->getFormatManager();
$formats = $fm->listFormats();
$detected = $fm->detectFormat($message);
$converted = $fm->convertFormat($msg, 'json_v1', 'compact');
```

**Try It**:
1. Go to Format System tab
2. Paste a message: `{"method":"POST","endpoint":"/api/users"}`
3. Click "Detect Format"
4. View detected format with confidence score

---

### 3. Template Management

**Location**: Templates tab

**Capabilities**:
- **Browse Templates**: View all registered Tera templates
- **Discover Similar**: Find templates by geometric similarity
- **Template Capabilities**: View 8D capability vectors

**Modern API Pattern**:
```php
$tm = $client->getTemplateManager();
$tm->registerTemplate('my-template', '<div>{{title}}</div>', ['ttl' => 3600]);
$html = $tm->renderTemplate('my-template', ['title' => 'Hello']);
$similar = $tm->discoverSimilar('my-template', 0.5);
```

**8D Capability Dimensions**:
1. **html**: Type identifier (1.0 for HTML templates)
2. **complexity**: Lines of code / 100
3. **interactivity**: Forms + inputs + scripts
4. **data_density**: Variables per 100 chars
5. **reusability**: Include count / 5
6. **cacheability**: 1 - (data_density × interactivity)
7. **semantic_layout**: Structural HTML5 elements / 5
8. **render_cost**: Estimated cost (default 0.5)

---

### 4. 3D Topology Visualization

**Location**: 3D Topology tab

**What It Shows**:
- Services positioned in 3D space (projected from 8D)
- Distance = Similarity in capability space
- Interactive axes selection (X, Y, Z)

**How to Use**:
1. Click "Service Topology" or "Template Topology"
2. Select axes from dropdowns (e.g., Network, Compute, Storage)
3. View services as colored spheres
4. Hover for details (ID, coordinates, metadata)

**8D Capability Dimensions**:
- **Storage**: Data persistence (0.0=none, 1.0=database)
- **Compute**: Processing power (0.0=minimal, 1.0=ML inference)
- **Network**: Network I/O (0.0=low, 1.0=reverse proxy)
- **Security**: Auth & encryption (0.0=basic, 1.0=hardened)
- **Latency**: Low-latency requirement (0.0=tolerant, 1.0=ultra-low)
- **Throughput**: High throughput (0.0=low, 1.0=streaming)
- **Reliability**: Fault tolerance (0.0=fragile, 1.0=resilient)
- **Scalability**: Horizontal scaling (0.0=fixed, 1.0=elastic)

---

### 5. Playground

**Location**: Playground tab

**Quick Actions**:
- **Ping Daemon**: Test connectivity
- **List All Formats**: Display registered formats
- **Convert Format**: Transform sample message

**Try It**:
1. Go to Playground tab
2. Click any quick action button
3. View JSON response

---

## Architecture

### What Changed in v2.0

**Facade Pattern Everywhere:**
```php
// ❌ OLD (v1.0):
$result = $client->executeCommand('list_formats', []);
$result = $client->executeCommand('discover_templates_by_capability', ['filters' => []]);

// ✅ NEW (v2.0):
$fm = $client->getFormatManager();
$result = $fm->listFormats();

$tm = $client->getTemplateManager();
$result = $tm->discoverByCapability([]);
```

**Enhanced Error Handling:**
```php
// ❌ OLD:
catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

// ✅ NEW:
catch (\gCore\GSD\Exception\ConnectionException $e) {
    http_response_code(503);
    echo json_encode([
        'error' => 'Daemon unavailable',
        'action' => 'Start daemon: cd ~/gh/GSD && ./scripts/start-daemon.sh'
    ]);
}
```

**Status Dashboard (NEW):**
- Real-time daemon health monitoring
- Connection pool statistics
- Supported commands count (50)
- ValKey functions count (127)

For detailed architecture rationale, see: [../ARCHITECTURE.md](../ARCHITECTURE.md)

---

## Troubleshooting

### Daemon Not Running

**Symptom**:
```
❌ Connection Error: Daemon unavailable
```

**Solution**:
```bash
cd ~/gh/GSD
./scripts/start-daemon.sh

# Verify
ps aux | grep gsd-daemon
```

---

### Templates Not Rendering

**Symptom**:
```
Error: Template rendering failed: no html received
```

**Fixed in v2.0**:
This was a bug where wrong parameters were used. Now fixed in TemplateManager.

**Verification**:
```bash
grep -A 5 "renderTemplate" src/Template/TemplateManager.php | grep template_id
# Should show: 'template_id' => $templateId
```

---

### Format Detection Returns "Unknown"

**Explanation**:
Format detection is client-side. If message doesn't match registered patterns, returns "unknown".

**Solution**:
```php
$fm = $client->getFormatManager();
$formats = $fm->listFormats(); // Check registered formats

// Register new format if needed
$fm->registerFormat('my_format', [
    'name' => 'my_format',
    'version' => '1.0.0',
    'patterns' => ['^\\{"custom_field":', ...]
]);
```

---

### 3D Topology Shows "No Services"

**Solution**:
```bash
cd ~/gh/GSD-Client/demo
php load_demo_data.php

# Verify
php -r "require '../vendor/autoload.php'; \$c = new gCore\GSD\Client(new gCore\GSD\Storage\ValKeyStorage(['host' => '127.0.0.1', 'port' => 6379, 'password' => '$(cat .gsd/valkey.password)']), 'default', 'default'); \$r = \$c->executeCommand('geometric_discover', ['requirements' => []]); echo 'Services: ' . count(\$r['matches'] ?? []);"
```

---

## File Structure

```
demo/
├── README.md                       # This file (v2.0 guide)
├── index.php                       # Main UI (MODERNIZED)
│   ├── AJAX Handlers (lines 27-204): Facades + error boundaries
│   ├── HTML UI (lines 207-725): Tabs + Status Dashboard
│   └── JavaScript (lines 727-1299): API + rendering
│
├── load_demo_templates.php         # Service templates (MODERNIZED)
├── register_example_data.php       # Tera templates (MODERNIZED)
├── load_demo_data.php              # Formats + services
├── register_real_services.php      # Real service examples
├── load_all_demo_data.php          # Master loader
└── ...
```

**Modernized Files**:
- ✅ index.php: Template/Format facades, Status dashboard, Enhanced errors
- ✅ load_demo_templates.php: TemplateManager facade for verification
- ✅ register_example_data.php: TemplateManager facade for registration

---

## Next Steps

1. **Load Data**: `php load_all_demo_data.php`
2. **Start Server**: `php -S localhost:8080`
3. **Open Browser**: http://localhost:8080
4. **Explore Tabs**: Overview → Status Dashboard → Templates → Topology
5. **Read Docs**: [ARCHITECTURE.md](../ARCHITECTURE.md), [AUDIT_REPORT.md](../AUDIT_REPORT.md)

---

**🎉 Enjoy the modernized GSD demo!**

*Version: 2.0 (Modernized 2025-10-18)*
*Modernization: Claude Code (Sonnet 4.5)*
