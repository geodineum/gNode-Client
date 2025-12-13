#!/bin/bash
# GSD Interactive Demo Startup Script

echo "╔══════════════════════════════════════════════════════════════╗"
echo "║           GSD Interactive Demo - Starting...                 ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""

# Change to demo directory
cd "$(dirname "$0")"

# Check if daemon is running
if ! pgrep -f "gsd-daemon" > /dev/null; then
    echo "⚠️  WARNING: GSD daemon is not running!"
    echo "   Start it with: /home/nierto/gh/GSD/daemon/scripts/start-daemon.sh"
    echo ""
fi

# Check if data is populated
echo "Checking if example data is registered..."
cd /home/nierto/gh/GSD-Client
FORMAT_COUNT=$(php -r '
require_once "vendor/autoload.php";
use gCore\GSD\Client;
use gCore\GSD\Storage\ValKeyStorage;
$storage = new ValKeyStorage(["host" => "127.0.0.1", "port" => 6379, "password" => "$(cat .gsd/valkey.password)"]);
$client = new Client($storage, "default", "default", ["debug" => false, "timeout" => 5.0]);
try {
    $formats = $client->executeCommand("list_formats", []);
    echo count($formats);
} catch (Exception $e) {
    echo "0";
}
' 2>/dev/null)

if [ "$FORMAT_COUNT" -lt "3" ]; then
    echo "📦 Populating example data..."
    php demo/register_example_data.php
    echo ""
else
    echo "✓ Found $FORMAT_COUNT formats already registered"
    echo ""
fi

# Find available port
PORT=8888
while lsof -Pi :$PORT -sTCP:LISTEN -t >/dev/null 2>&1; do
    PORT=$((PORT + 1))
done

echo "🚀 Starting PHP development server on port $PORT..."
echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  Access the demo at: http://localhost:$PORT/index.php       ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""
echo "Press Ctrl+C to stop the server"
echo ""

cd demo
php -S localhost:$PORT
