#!/bin/bash
# GSD Consumer Group Benchmarks
# This script runs all benchmarks and captures the results

# Configuration
RESULTS_DIR="benchmark_results"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
RESULTS_FILE="${RESULTS_DIR}/benchmark_${TIMESTAMP}.log"
GSD_DAEMON_STATUS="/tmp/gsd_daemon_status.txt"

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[0;33m'
NC='\033[0m' # No Color

# Create results directory
mkdir -p "$RESULTS_DIR"

# Function to check if the GSD daemon is running
check_daemon() {
  echo -e "${YELLOW}Checking if GSD daemon is running...${NC}"
  if ps aux | grep -v grep | grep "gsd-daemon" > /dev/null; then
    echo -e "${GREEN}✓ GSD daemon is running${NC}"
    return 0
  else
    echo -e "${RED}✗ GSD daemon is not running${NC}"
    echo -e "Start the daemon with: ${YELLOW}cd /home/ebisu/gsd && RUST_LOG=info ./daemon/target/release/gsd-daemon --site-id default --node-id default --debug${NC}"
    return 1
  fi
}

# Function to check if ValKey/Redis is running
check_valkey() {
  echo -e "${YELLOW}Checking if ValKey is running...${NC}"
  if timeout 2 redis-cli ping > /dev/null 2>&1; then
    echo -e "${GREEN}✓ ValKey/Redis is running${NC}"
    return 0
  else
    echo -e "${RED}✗ ValKey/Redis is not running${NC}"
    echo -e "Ensure ValKey/Redis is running with: ${YELLOW}docker exec valkey valkey-cli ping${NC}"
    return 1
  fi
}

# Function to run a benchmark
run_benchmark() {
  local name=$1
  local command=$2
  local description=$3

  echo -e "\n${YELLOW}Running benchmark: ${name}${NC}"
  echo -e "${description}\n"
  
  echo "========== ${name} ==========" >> "$RESULTS_FILE"
  echo "Command: ${command}" >> "$RESULTS_FILE"
  echo "Timestamp: $(date)" >> "$RESULTS_FILE"
  echo "" >> "$RESULTS_FILE"
  
  # Run the benchmark and capture output
  php "$command" | tee -a "$RESULTS_FILE"
  
  echo -e "\n${GREEN}Benchmark completed: ${name}${NC}"
  echo "Results saved to: $RESULTS_FILE"
  echo "" >> "$RESULTS_FILE"
  echo "-------------------------------------------" >> "$RESULTS_FILE"
  echo "" >> "$RESULTS_FILE"
}

# Main script
echo -e "${GREEN}GSD Consumer Group Benchmarks${NC}"
echo -e "${YELLOW}================================${NC}\n"

# Check prerequisites
if ! check_daemon; then
  echo -e "${RED}Daemon check failed. Fix the issue before continuing.${NC}"
  exit 1
fi

if ! check_valkey; then
  echo -e "${RED}ValKey/Redis check failed. Fix the issue before continuing.${NC}"
  exit 1
fi

# Start benchmark session
echo -e "\n${GREEN}Starting benchmark session: ${TIMESTAMP}${NC}"
echo "Results will be saved to: $RESULTS_FILE"
echo "GSD Consumer Group Benchmarks - ${TIMESTAMP}" > "$RESULTS_FILE"
echo "=======================================" >> "$RESULTS_FILE"
echo "" >> "$RESULTS_FILE"

# Collect system information
echo "System Information:" >> "$RESULTS_FILE"
uname -a >> "$RESULTS_FILE"
php -v | head -n 1 >> "$RESULTS_FILE"
redis-cli info | grep redis_version >> "$RESULTS_FILE"
echo "" >> "$RESULTS_FILE"

# Collect GSD daemon info
echo "GSD Daemon Information:" >> "$RESULTS_FILE"
ps aux | grep gsd-daemon | grep -v grep >> "$RESULTS_FILE"
echo "" >> "$RESULTS_FILE"

# Run each benchmark

# 1. Consumer Group vs Script Benchmark (Basic)
run_benchmark "Consumer Group vs Script" "consumer_group_benchmark.php" "Compares the performance of consumer group operations vs script-based polling"

# 2. Multi-Client Benchmark with 10 clients
run_benchmark "Multi-Client (10 clients)" "multi_client_benchmark.php --clients=10 --ops=200 --batch=10" "Simulates 10 clients connecting simultaneously"

# 3. Multi-Client Benchmark with 20 clients
run_benchmark "Multi-Client (20 clients)" "multi_client_benchmark.php --clients=20 --ops=100 --batch=10" "Simulates 20 clients connecting simultaneously"

# 4. Scaling Test for 20k ops/sec projection
run_benchmark "20k ops/sec Projection" "multi_client_benchmark.php --clients=40 --ops=100 --batch=20" "Tests if system can scale to 20k ops/sec"

# Complete the benchmark session
echo -e "\n${GREEN}All benchmarks completed!${NC}"
echo -e "Results saved to: ${YELLOW}${RESULTS_FILE}${NC}"
echo -e "\nTo view results: ${YELLOW}less ${RESULTS_FILE}${NC}"

# Final summary
echo -e "\n${YELLOW}Summary:${NC}"
grep "throughput:" "$RESULTS_FILE" | sort
echo ""
echo -e "${GREEN}Benchmark session complete.${NC}"