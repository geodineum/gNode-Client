#!/bin/bash
echo "Testing GSD Demo Setup..."
echo ""

# Test 1: Check daemon
if pgrep -f "gsd-daemon" > /dev/null; then
    echo "✓ GSD Daemon is running"
else
    echo "✗ GSD Daemon is NOT running"
    echo "  Start with: cd /home/nierto/gh/GSD/daemon && ./scripts/start-daemon.sh"
fi

# Test 2: Check files
echo "✓ Demo files present:"
ls -1 *.php *.sh | sed 's/^/  - /'

echo ""
echo "To start the demo:"
echo "  cd /home/nierto/gh/GSD-Client/demo"
echo "  php -S localhost:8888"
echo ""
echo "Then open: http://localhost:8888/index.php"
