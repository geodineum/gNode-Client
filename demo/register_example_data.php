<?php
/**
 * GSD Demo: Register Example Formats and Templates
 *
 * This script populates GSD with diverse example data to demonstrate
 * the full potential of the geometric service discovery system.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use gCore\GSD\Client;
use gCore\GSD\Storage\ValKeyStorage;

// Initialize GSD Client
$storage = new ValKeyStorage([
    'host' => '127.0.0.1',
    'port' => 6379,
    'password' => trim(file_get_contents(__DIR__ . '/../.gsd/valkey.password'))
]);

$client = new Client($storage, 'default', 'default', [
    'debug' => false,
    'timeout' => 10.0,
    'use_consumer_groups' => true
]);

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║  GSD Demo: Populating Example Formats & Templates           ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

// ============================================================================
// SECTION 1: Register Diverse Message Formats
// ============================================================================

echo "📋 REGISTERING MESSAGE FORMATS\n";
echo str_repeat("─", 64) . "\n";

$formats = [
    // Format 1: RESTful API format
    [
        'format_name' => 'restful_api_v1',
        'version' => '1.0.0',
        'description' => 'RESTful API request/response format with HTTP semantics',
        'content_type' => 'application/json',
        'binary' => false,
        'detection_patterns' => [
            [
                'pattern_type' => 'regex',
                'pattern' => '"method"\\s*:\\s*"(GET|POST|PUT|DELETE|PATCH)"',
                'confidence' => 0.95
            ]
        ],
        'field_mapping' => [
            'request_id' => 'id',
            'method' => 'method',
            'endpoint' => 'endpoint',
            'headers' => 'headers',
            'body' => 'body',
            'timestamp' => 'timestamp'
        ],
        'schema' => [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'required' => ['request_id', 'method', 'endpoint'],
            'properties' => [
                'request_id' => ['type' => 'string'],
                'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'DELETE', 'PATCH']],
                'endpoint' => ['type' => 'string'],
                'headers' => ['type' => 'object'],
                'body' => ['type' => ['object', 'null']],
                'timestamp' => ['type' => 'number']
            ]
        ]
    ],

    // Format 2: Event-driven messaging
    [
        'format_name' => 'event_stream_v1',
        'version' => '1.0.0',
        'description' => 'Event-driven message format for pub/sub systems',
        'content_type' => 'application/json',
        'binary' => false,
        'detection_patterns' => [
            [
                'pattern_type' => 'regex',
                'pattern' => '"event_type"\\s*:',
                'confidence' => 0.90
            ]
        ],
        'field_mapping' => [
            'event_id' => 'id',
            'event_type' => 'type',
            'payload' => 'data',
            'source' => 'source',
            'occurred_at' => 'timestamp'
        ],
        'schema' => [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'required' => ['event_id', 'event_type', 'occurred_at'],
            'properties' => [
                'event_id' => ['type' => 'string'],
                'event_type' => ['type' => 'string'],
                'payload' => ['type' => 'object'],
                'source' => ['type' => 'string'],
                'occurred_at' => ['type' => 'number']
            ]
        ]
    ],

    // Format 3: GraphQL-style format
    [
        'format_name' => 'graphql_query_v1',
        'version' => '1.0.0',
        'description' => 'GraphQL query/mutation format',
        'content_type' => 'application/json',
        'binary' => false,
        'detection_patterns' => [
            [
                'pattern_type' => 'regex',
                'pattern' => '"query"\\s*:\\s*"(query|mutation|subscription)',
                'confidence' => 0.92
            ]
        ],
        'field_mapping' => [
            'operation_id' => 'id',
            'query' => 'query',
            'variables' => 'variables',
            'operation_name' => 'operationName'
        ],
        'schema' => [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'required' => ['query'],
            'properties' => [
                'operation_id' => ['type' => 'string'],
                'query' => ['type' => 'string'],
                'variables' => ['type' => 'object'],
                'operation_name' => ['type' => 'string']
            ]
        ]
    ],

    // Format 4: IoT Sensor Data
    [
        'format_name' => 'iot_sensor_v1',
        'version' => '1.0.0',
        'description' => 'IoT sensor telemetry data format',
        'content_type' => 'application/json',
        'binary' => false,
        'detection_patterns' => [
            [
                'pattern_type' => 'regex',
                'pattern' => '"sensor_id"\\s*:',
                'confidence' => 0.88
            ]
        ],
        'field_mapping' => [
            'sensor_id' => 'sensor_id',
            'reading_type' => 'type',
            'value' => 'value',
            'unit' => 'unit',
            'recorded_at' => 'timestamp',
            'location' => 'location'
        ],
        'schema' => [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'required' => ['sensor_id', 'reading_type', 'value', 'recorded_at'],
            'properties' => [
                'sensor_id' => ['type' => 'string'],
                'reading_type' => ['type' => 'string'],
                'value' => ['type' => 'number'],
                'unit' => ['type' => 'string'],
                'recorded_at' => ['type' => 'number'],
                'location' => [
                    'type' => 'object',
                    'properties' => [
                        'lat' => ['type' => 'number'],
                        'lon' => ['type' => 'number']
                    ]
                ]
            ]
        ]
    ],

    // Format 5: Microservice RPC
    [
        'format_name' => 'micro_rpc_v1',
        'version' => '1.0.0',
        'description' => 'Microservice RPC call format with tracing',
        'content_type' => 'application/json',
        'binary' => false,
        'detection_patterns' => [
            [
                'pattern_type' => 'regex',
                'pattern' => '"service_name"\\s*:\\s*"[^"]+",\\s*"method"',
                'confidence' => 0.93
            ]
        ],
        'field_mapping' => [
            'call_id' => 'id',
            'service_name' => 'service',
            'method' => 'method',
            'arguments' => 'args',
            'trace_id' => 'trace_id',
            'span_id' => 'span_id',
            'parent_span_id' => 'parent_span_id'
        ],
        'schema' => [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'required' => ['call_id', 'service_name', 'method'],
            'properties' => [
                'call_id' => ['type' => 'string'],
                'service_name' => ['type' => 'string'],
                'method' => ['type' => 'string'],
                'arguments' => ['type' => 'array'],
                'trace_id' => ['type' => 'string'],
                'span_id' => ['type' => 'string'],
                'parent_span_id' => ['type' => 'string']
            ]
        ]
    ],

    // Format 6: Log aggregation format
    [
        'format_name' => 'structured_log_v1',
        'version' => '1.0.0',
        'description' => 'Structured logging format for centralized log aggregation',
        'content_type' => 'application/json',
        'binary' => false,
        'detection_patterns' => [
            [
                'pattern_type' => 'regex',
                'pattern' => '"level"\\s*:\\s*"(DEBUG|INFO|WARN|ERROR|FATAL)"',
                'confidence' => 0.91
            ]
        ],
        'field_mapping' => [
            'log_id' => 'id',
            'level' => 'level',
            'message' => 'message',
            'logger_name' => 'logger',
            'thread' => 'thread',
            'context' => 'context',
            'logged_at' => 'timestamp'
        ],
        'schema' => [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'required' => ['level', 'message', 'logged_at'],
            'properties' => [
                'log_id' => ['type' => 'string'],
                'level' => ['type' => 'string', 'enum' => ['DEBUG', 'INFO', 'WARN', 'ERROR', 'FATAL']],
                'message' => ['type' => 'string'],
                'logger_name' => ['type' => 'string'],
                'thread' => ['type' => 'string'],
                'context' => ['type' => 'object'],
                'logged_at' => ['type' => 'number']
            ]
        ]
    ],

    // Format 7: Webhook notification format
    [
        'format_name' => 'webhook_v1',
        'version' => '1.0.0',
        'description' => 'Webhook notification format for external integrations',
        'content_type' => 'application/json',
        'binary' => false,
        'detection_patterns' => [
            [
                'pattern_type' => 'regex',
                'pattern' => '"webhook_id"\\s*:',
                'confidence' => 0.87
            ]
        ],
        'field_mapping' => [
            'webhook_id' => 'id',
            'event' => 'event',
            'data' => 'data',
            'signature' => 'signature',
            'delivered_at' => 'timestamp'
        ],
        'schema' => [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'required' => ['webhook_id', 'event', 'data'],
            'properties' => [
                'webhook_id' => ['type' => 'string'],
                'event' => ['type' => 'string'],
                'data' => ['type' => 'object'],
                'signature' => ['type' => 'string'],
                'delivered_at' => ['type' => 'number']
            ]
        ]
    ]
];

foreach ($formats as $i => $format) {
    try {
        $result = $client->executeCommand('register_format', ['format_definition' => $format]);
        echo sprintf("✓ [%d/7] Registered: %-20s (%s)\n",
            $i + 1,
            $format['format_name'],
            $format['description']
        );
    } catch (Exception $e) {
        echo sprintf("✗ [%d/7] Failed: %-20s - %s\n",
            $i + 1,
            $format['format_name'],
            $e->getMessage()
        );
    }
}

echo "\n";

// ============================================================================
// SECTION 2: Register Diverse Templates with Different Capabilities
// ============================================================================

echo "🎨 REGISTERING TEMPLATE FRAGMENTS\n";
echo str_repeat("─", 64) . "\n";

$templates = [
    // Simple static templates (low complexity, high cacheability)
    [
        'template_id' => 'demo-static-header',
        'content' => '<header><h1>{{site_name}}</h1><nav>{{navigation}}</nav></header>',
        'ttl' => 86400,
        'tags' => ['static', 'layout', 'header']
    ],
    [
        'template_id' => 'demo-static-footer',
        'content' => '<footer><p>&copy; {{year}} {{company}}</p>{{social_links}}</footer>',
        'ttl' => 86400,
        'tags' => ['static', 'layout', 'footer']
    ],

    // Form templates (medium complexity, low cacheability)
    [
        'template_id' => 'demo-login-form',
        'content' => '<form method="POST"><input name="username" required>{{csrf_token}}<input type="password" name="password" required><button>Login</button></form>',
        'ttl' => 300,
        'tags' => ['form', 'auth', 'dynamic']
    ],
    [
        'template_id' => 'demo-registration-form',
        'content' => '<form method="POST">{{csrf_token}}<input name="email" type="email" required><input name="username" required><input type="password" name="password" required><input type="password" name="password_confirm" required>{{captcha}}<button>Register</button></form>',
        'ttl' => 300,
        'tags' => ['form', 'auth', 'complex']
    ],
    [
        'template_id' => 'demo-contact-form',
        'content' => '<form method="POST">{{csrf_token}}<input name="name" required><input name="email" type="email" required><textarea name="message" required></textarea><button>Send</button></form>',
        'ttl' => 3600,
        'tags' => ['form', 'contact']
    ],

    // Content display templates (medium complexity, medium cacheability)
    [
        'template_id' => 'demo-blog-post',
        'content' => '<article><h2>{{title}}</h2><p class="meta">By {{author}} on {{date}}</p><div class="content">{{content}}</div>{{comments}}</article>',
        'ttl' => 1800,
        'tags' => ['content', 'blog', 'article']
    ],
    [
        'template_id' => 'demo-product-card',
        'content' => '<div class="product"><img src="{{image}}" alt="{{name}}"><h3>{{name}}</h3><p>{{description}}</p><span class="price">{{price}}</span>{{add_to_cart}}</div>',
        'ttl' => 600,
        'tags' => ['ecommerce', 'product', 'dynamic']
    ],
    [
        'template_id' => 'demo-user-profile',
        'content' => '<div class="profile"><img src="{{avatar}}" alt="{{username}}"><h2>{{username}}</h2><p>{{bio}}</p><ul>{{stats}}</ul>{{activity_feed}}</div>',
        'ttl' => 300,
        'tags' => ['user', 'profile', 'personalized']
    ],

    // Dashboard/analytics templates (high complexity, low cacheability)
    [
        'template_id' => 'demo-analytics-dashboard',
        'content' => '<div class="dashboard"><div class="metrics">{{kpi_cards}}</div><div class="charts">{{revenue_chart}}{{traffic_chart}}{{conversion_chart}}</div><div class="tables">{{recent_orders}}{{top_products}}</div>{{realtime_feed}}</div>',
        'ttl' => 60,
        'tags' => ['dashboard', 'analytics', 'realtime', 'complex']
    ],
    [
        'template_id' => 'demo-admin-panel',
        'content' => '<div class="admin"><aside>{{admin_nav}}</aside><main>{{breadcrumbs}}<div class="actions">{{quick_actions}}</div><div class="grid">{{user_stats}}{{system_health}}{{recent_activity}}</div>{{data_table}}</main></div>',
        'ttl' => 120,
        'tags' => ['admin', 'dashboard', 'complex']
    ],
    [
        'template_id' => 'demo-monitoring-board',
        'content' => '<div class="monitoring"><script>{{websocket_init}}</script><div class="alerts">{{active_alerts}}</div><div class="metrics">{{cpu_gauge}}{{memory_gauge}}{{disk_gauge}}{{network_gauge}}</div><div class="logs">{{live_logs}}</div>{{incident_timeline}}</div>',
        'ttl' => 30,
        'tags' => ['monitoring', 'realtime', 'system', 'very-complex']
    ],

    // List/table templates (varying complexity)
    [
        'template_id' => 'demo-simple-list',
        'content' => '<ul>{{#items}}<li>{{name}}</li>{{/items}}</ul>',
        'ttl' => 3600,
        'tags' => ['list', 'simple']
    ],
    [
        'template_id' => 'demo-data-table',
        'content' => '<table><thead>{{headers}}</thead><tbody>{{rows}}</tbody></table>{{pagination}}',
        'ttl' => 600,
        'tags' => ['table', 'data', 'pagination']
    ],

    // Email templates (high variability)
    [
        'template_id' => 'demo-email-welcome',
        'content' => '<html><body><h1>Welcome {{username}}!</h1><p>{{welcome_message}}</p>{{cta_button}}{{footer}}</body></html>',
        'ttl' => 7200,
        'tags' => ['email', 'transactional']
    ],
    [
        'template_id' => 'demo-email-notification',
        'content' => '<html><body><h2>{{notification_title}}</h2><p>{{message}}</p>{{action_link}}{{unsubscribe}}</body></html>',
        'ttl' => 1800,
        'tags' => ['email', 'notification']
    ],

    // Widget templates (micro-components)
    [
        'template_id' => 'demo-widget-weather',
        'content' => '<div class="widget weather"><span class="temp">{{temperature}}°</span><span class="condition">{{condition}}</span>{{forecast}}</div>',
        'ttl' => 900,
        'tags' => ['widget', 'weather', 'external-api']
    ]
];

// Register templates using modern TemplateManager facade
$tm = $client->getTemplateManager();
$successCount = 0;
$errorCount = 0;
$errors = [];

foreach ($templates as $i => $template) {
    try {
        // Use TemplateManager facade instead of direct executeCommand
        $result = $tm->registerTemplate(
            $template['template_id'],
            $template['content'],
            [
                'ttl' => $template['ttl'] ?? 3600,
                'tags' => $template['tags'] ?? []
            ]
        );

        $successCount++;
        echo sprintf("✓ [%2d/15] Registered: %-30s (TTL: %ds)\n",
            $i + 1,
            $template['template_id'],
            $template['ttl'] ?? 3600
        );
    } catch (\gCore\GSD\Exception\ConnectionException $e) {
        $errorCount++;
        $errors[] = [
            'template' => $template['template_id'],
            'error' => 'Connection error: ' . $e->getMessage()
        ];
        echo sprintf("✗ [%2d/15] Failed: %-30s - Connection Error\n",
            $i + 1,
            $template['template_id']
        );
    } catch (\gCore\GSD\Exception\GSDException $e) {
        $errorCount++;
        $errors[] = [
            'template' => $template['template_id'],
            'error' => 'GSD error: ' . $e->getMessage()
        ];
        echo sprintf("✗ [%2d/15] Failed: %-30s - GSD Error\n",
            $i + 1,
            $template['template_id']
        );
    } catch (Exception $e) {
        $errorCount++;
        $errors[] = [
            'template' => $template['template_id'],
            'error' => $e->getMessage()
        ];
        echo sprintf("✗ [%2d/15] Failed: %-30s - %s\n",
            $i + 1,
            $template['template_id'],
            substr($e->getMessage(), 0, 40)
        );
    }
}

echo "\n" . str_repeat("=", 70) . "\n";
echo "📊 Registration Summary:\n";
echo "  • 7 message formats registered\n";
echo "  • Templates: $successCount successful, $errorCount failed (" . count($templates) . " total)\n";

if ($errorCount > 0) {
    echo "\n⚠️  Errors encountered:\n";
    foreach ($errors as $error) {
        echo "  ✗ " . $error['template'] . ": " . $error['error'] . "\n";
    }
}

if ($successCount === count($templates)) {
    echo "\n✅ All templates registered successfully!\n";
    echo "🚀 Ready for interactive demo at demo/index.php\n\n";
} elseif ($successCount > 0) {
    echo "\n⚠️  Partial success: Some templates registered, but errors occurred.\n";
    echo "ℹ️  Check the daemon logs for more information.\n\n";
} else {
    echo "\n❌ No templates were registered successfully.\n";
    echo "ℹ️  Make sure the GSD daemon is running: cd ~/gh/GSD && ./scripts/start-daemon.sh\n\n";
}
