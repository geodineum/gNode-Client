# GSD Demo - Quick Start Guide

## One-Command Start

```bash
cd /home/nierto/gh/GSD-Client/demo
./start-demo.sh
```

Then open **http://localhost:8888/index.php** (or whatever port the script shows) in your browser.

---

## Manual Start (If Script Fails)

### Step 1: Ensure Daemon is Running

```bash
ps aux | grep gsd-daemon
```

If not running:

```bash
cd /home/nierto/gh/GSD/daemon
./scripts/start-daemon.sh
```

### Step 2: Populate Example Data (First Time Only)

```bash
cd /home/nierto/gh/GSD-Client
php demo/register_example_data.php
```

Expected output:
```
✓ [1/7] Registered: restful_api_v1
✓ [2/7] Registered: event_stream_v1
...
✓ [14/15] Registered: demo-email-welcome
```

### Step 3: Start Web Server

```bash
cd /home/nierto/gh/GSD-Client/demo
php -S localhost:8888
```

### Step 4: Open Browser

Navigate to: **http://localhost:8888/index.php**

---

## What You Should See

### On Load
- ✅ **Daemon Status**: Green "● Daemon Online"
- ✅ **Registered Formats**: 7+ formats
- ✅ **Template Fragments**: 14+ templates
- ✅ **Dimensions**: 64
- ✅ **Discovery Speed**: O(1)

### Tabs to Explore

1. **📊 Overview**
   - System architecture
   - Key features
   - Statistics

2. **📋 Format System**
   - Browse 7 registered formats
   - Test format auto-detection
   - See JSONSchema definitions

3. **🎨 Templates**
   - View 14 template fragments
   - Discover similar templates geometrically
   - See capability-based filtering

4. **🌌 3D Topology**
   - Visualize service topology
   - Select meaningful axes
   - Observe template clustering

5. **🧪 Playground**
   - Test API commands
   - See raw JSON responses
   - Experiment with GSD

---

## Troubleshooting

### "Daemon Offline" Error

**Check daemon status:**
```bash
ps aux | grep gsd-daemon
```

**Start daemon:**
```bash
cd /home/nierto/gh/GSD/daemon
cargo build --release
./target/release/gsd-daemon \
  --redis-host 127.0.0.1 \
  --redis-port 6379 \
  --redis-auth "$(cat .gsd/valkey.password)" \
  start &
```

### "0 Formats Registered"

**Re-run data population:**
```bash
cd /home/nierto/gh/GSD-Client
php demo/register_example_data.php
```

### Port Already in Use

**Kill existing server:**
```bash
pkill -f "php -S"
```

**Use different port:**
```bash
php -S localhost:9999
```

### PHP Not Found

**Check PHP installation:**
```bash
which php
php -v
```

Should show PHP 8.0+ installed.

### ValKey Connection Error

**Check ValKey/Redis:**
```bash
docker ps | grep valkey
docker exec valkey valkey-cli PING
```

Should return `PONG`.

---

## Testing Without Browser

### Test Backend Directly

```bash
cd /home/nierto/gh/GSD-Client

# Test ping
php -r '
require "vendor/autoload.php";
use gCore\GSD\Client;
use gCore\GSD\Storage\ValKeyStorage;
$storage = new ValKeyStorage(["host" => "127.0.0.1", "port" => 6379, "password" => "$(cat .gsd/valkey.password)"]);
$client = new Client($storage, "default", "default");
echo $client->executeCommand("ping") ? "✓ Ping OK\n" : "✗ Ping Failed\n";
'

# Test list formats
php -r '
require "vendor/autoload.php";
use gCore\GSD\Client;
use gCore\GSD\Storage\ValKeyStorage;
$storage = new ValKeyStorage(["host" => "127.0.0.1", "port" => 6379, "password" => "$(cat .gsd/valkey.password)"]);
$client = new Client($storage, "default", "default");
$formats = $client->executeCommand("list_formats", []);
echo "Found " . count($formats) . " formats\n";
'
```

### Test API Endpoints (with server running)

```bash
# Ping
curl -s http://localhost:8888/index.php?action=ping

# List formats
curl -s http://localhost:8888/index.php?action=list_formats | jq .

# Get topology
curl -s http://localhost:8888/index.php?action=get_topology | jq .
```

---

## Next Steps

Once the demo is running:

1. **Explore the tabs** - Each demonstrates different GSD capabilities
2. **Test format detection** - Paste various JSON messages
3. **Discover similar templates** - See geometric clustering in action
4. **Visualize topology** - Change axes and observe groupings
5. **Read the docs** - Check `README.md` and `DEMO_SUMMARY.md`

---

## Need Help?

- **Documentation**: Check `demo/README.md` for detailed explanations
- **Architecture**: See `/home/nierto/gh/GSD/CLAUDE.md`
- **Command Reference**: See `/home/nierto/gh/GSD/CLAUDE_COMMAND_REFERENCE.md`
- **Format System**: See `/home/nierto/gh/GSD/daemon/examples/format_definitions/README.md`

---

Enjoy exploring the geometric topology! 🌌🚀
