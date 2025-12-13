# GSD Client Benchmarking Tools

This directory contains tools for benchmarking the GSD client performance, specifically comparing the script-based polling approach with the new consumer group implementation.

## Available Benchmarks

### 1. Consumer Group vs. Script Polling

**File:** `consumer_group_benchmark.php`

This benchmark compares the performance of consumer group-based operations versus script-based polling for basic GSD operations like ping and findServices.

**Usage:**
```bash
php consumer_group_benchmark.php
```

### 2. Multi-Client Throughput 

**File:** `multi_client_benchmark.php`

This benchmark simulates multiple clients connecting to the GSD daemon simultaneously to demonstrate the high throughput capability of the consumer group approach.

**Usage:**
```bash
php multi_client_benchmark.php [--clients=10] [--ops=200] [--batch=10] [--timeout=5]
```

**Parameters:**
- `--clients`: Number of simulated clients (default: 10)
- `--ops`: Operations per client (default: 200)
- `--batch`: Batch size for operations (default: 10)
- `--timeout`: Operation timeout in seconds (default: 5)

To test if the system can handle 20k operations per second, run with 40 clients with batch size 10:

```bash
php multi_client_benchmark.php --clients=40 --ops=500 --batch=10
```

### 3. Stream Performance Test

**File:** `stream_performance_test.php`

Low-level benchmark testing raw stream throughput without the GSD client abstraction layer.

## Setup Requirements

Before running benchmarks:

1. Ensure ValKey/Redis is running:
   ```bash
   docker exec valkey valkey-cli ping
   ```

2. Start the GSD daemon:
   ```bash
   cd /home/ebisu/gsd
   RUST_LOG=info ./daemon/target/release/gsd-daemon --site-id default --node-id default --debug
   ```

3. Install PHP dependencies:
   ```bash
   cd /home/ebisu/gsd-client
   composer install
   ```

## Interpreting Results

### Consumer Group Performance

The consumer group approach should show significantly better performance than script-based polling:

- **Throughput**: 4-8x higher operations per second
- **Latency**: 50-80% lower average and p95 latencies
- **Scalability**: Near-linear scaling with multiple clients

### Multi-Client Scaling

This benchmark helps validate that the GSD system can handle high throughput with multiple clients:

- **Linear Region**: Up to a certain number of clients, throughput should scale linearly
- **Saturation Point**: The point where adding more clients no longer increases throughput
- **Projected Maximum**: Estimated maximum throughput based on benchmark results

### Testing 20k ops/sec

To verify the system can handle 20k operations per second:

1. Run the multi-client benchmark with increasing client counts (10, 20, 30, 40)
2. Plot the throughput vs. client count to understand scaling behavior
3. Look for the "knee" in the curve where throughput starts to level off
4. Determine if 20k ops/sec is achievable by extrapolating the curve

## Optimizing for Maximum Throughput

To achieve highest possible throughput:

1. Increase `batch_size` (10-25 is optimal for most workloads)
2. Adjust ValKey/Redis configuration (maxclients, maxmemory)
3. Tune GSD daemon parameters (RUST_LOG=info for optimal performance)
4. Use multiple ValKey/Redis instances with sharding for extreme scale

## Common Issues

- **Connection Errors**: Ensure ValKey/Redis and GSD daemon are running
- **Memory Exhaustion**: Reduce the number of clients or operations per client
- **High CPU Usage**: Reduce batch size to prevent CPU saturation
- **Timeouts**: Increase timeout value if operations are timing out