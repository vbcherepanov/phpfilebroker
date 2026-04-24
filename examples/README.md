# Examples

Full-featured demos covering all File Broker capabilities.
Storage root: `/tmp/file-broker-demo`.

## Quick start

```bash
# 1. Install deps (once)
make install

# 2. Run producer
php examples/producer.php

# 3. Run consumer (reads messages produced above)
php examples/consumer.php

# 4. Run worker (long-running, Ctrl+C to stop)
php examples/worker.php

# 5. Run exchange routing demo
php examples/exchange_demo.php
```

## Files

| Script | What it shows |
|---|---|
| `producer.php` | Priority (0-255), batch produce, publish via topic exchange, deduplication by key, producer confirms, queue stats, metrics snapshot |
| `consumer.php` | Consume + ack, batch ack, reject/retry, dead letter, stream mode + consumer groups, stream replay, metrics |
| `worker.php` | Long-running worker with graceful shutdown (SIGTERM/SIGINT), callback handler, metrics recording |
| `exchange_demo.php` | Topic/fanout/direct/headers exchange routing with pass/fail assertions |

## Docker

```bash
docker compose run --rm php php examples/producer.php
docker compose run --rm php php examples/consumer.php
docker compose run --rm php php examples/worker.php
docker compose run --rm php php examples/exchange_demo.php
```
