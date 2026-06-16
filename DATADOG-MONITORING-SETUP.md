# Datadog Monitoring Setup Guide
## Post45 Journal Infrastructure Monitoring

**Purpose**: Comprehensive monitoring and observability for Post45's main site and OJS submissions platform using Datadog.

**Infrastructure:**
- **Main Site**: https://post45.org
- **OJS Submissions**: https://submissions.post45.org
- **Server**: DigitalOcean Ubuntu 22.04 droplet (1 vCPU, 1GB RAM)

---

## Overview

This guide sets up monitoring for:
1. System health (CPU, memory, disk, network)
2. Apache web server metrics
3. MySQL database performance
4. PHP-FPM application metrics
5. OJS application logs
6. Email delivery monitoring (Mailgun integration)
7. Uptime and synthetic monitoring
8. Custom OJS-specific metrics

---

## Part 1: Initial Datadog Setup

### 1.1 Get Your API Key

1. Log into Datadog: https://app.datadoghq.com
2. Navigate to **Organization Settings → API Keys**
3. Copy your API key (or create a new one for this host)
4. Note your Datadog site: `datadoghq.com` (US1), `datadoghq.eu` (EU), etc.

### 1.2 Install Datadog Agent

SSH into your server as `ojsadmin`:

```bash
# Install Datadog Agent (replace with your actual API key)
DD_API_KEY=your_api_key_here \
DD_SITE="datadoghq.com" \
bash -c "$(curl -L https://s3.amazonaws.com/dd-agent/scripts/install_script_agent7.sh)"
```

**Verify installation:**
```bash
sudo systemctl status datadog-agent
sudo datadog-agent status
```

### 1.3 Configure Basic Tags

Tags help organize your infrastructure. Edit the main config:

```bash
sudo nano /etc/datadog-agent/datadog.yaml
```

Add these tags (around line 40-50):

```yaml
tags:
  - env:production
  - service:post45
  - project:ojs-submissions
  - host:ojs-prod-01
  - location:nyc3-digitalocean
```

Restart agent:
```bash
sudo systemctl restart datadog-agent
```

---

## Part 2: Apache Integration

### 2.1 Enable Apache mod_status

```bash
# Enable mod_status module
sudo a2enmod status

# Create Datadog-specific status endpoint
sudo nano /etc/apache2/conf-available/datadog-status.conf
```

Add this configuration:

```apache
<Location "/server-status">
    SetHandler server-status
    Require local
    Require ip 127.0.0.1
</Location>

ExtendedStatus On
```

```bash
# Enable configuration
sudo a2enconf datadog-status
sudo systemctl reload apache2

# Test it works
curl http://127.0.0.1/server-status?auto
```

### 2.2 Configure Datadog Apache Integration

```bash
sudo nano /etc/datadog-agent/conf.d/apache.d/conf.yaml
```

```yaml
init_config:

instances:
  - apache_status_url: http://127.0.0.1/server-status?auto
    tags:
      - service:apache
      - component:webserver
```

```bash
# Restart agent
sudo systemctl restart datadog-agent

# Verify integration
sudo datadog-agent status | grep -A 10 apache
```

---

## Part 3: MySQL Integration

### 3.1 Create Datadog MySQL User

```bash
# Connect to MySQL
sudo mysql -u root -p
```

```sql
-- Create monitoring user with required privileges
CREATE USER 'datadog'@'localhost' IDENTIFIED BY 'secure_password_here';

-- Grant necessary permissions
GRANT REPLICATION CLIENT ON *.* TO 'datadog'@'localhost';
GRANT PROCESS ON *.* TO 'datadog'@'localhost';
GRANT SELECT ON performance_schema.* TO 'datadog'@'localhost';

-- For OJS-specific monitoring
GRANT SELECT ON ojs.* TO 'datadog'@'localhost';

FLUSH PRIVILEGES;
EXIT;
```

### 3.2 Configure Datadog MySQL Integration

```bash
sudo nano /etc/datadog-agent/conf.d/mysql.d/conf.yaml
```

```yaml
init_config:

instances:
  - host: localhost
    port: 3306
    username: datadog
    password: secure_password_here
    tags:
      - service:mysql
      - component:database
      - db:ojs

    options:
      replication: false
      galera_cluster: false
      extra_status_metrics: true
      extra_innodb_metrics: true
      extra_performance_metrics: true
      schema_size_metrics: true

    queries:
      # Monitor submission count
      - query: SELECT COUNT(*) as submission_count FROM submissions;
        columns:
          - name: submissions.total
            type: gauge
        tags:
          - metric_type:ojs_submissions

      # Monitor active submissions by stage
      - query: |
          SELECT stage_id, COUNT(*) as count
          FROM submissions
          WHERE status = 1
          GROUP BY stage_id;
        columns:
          - name: stage_id
            type: tag
          - name: submissions.active
            type: gauge
        tags:
          - metric_type:ojs_workflow
```

```bash
# Restart agent
sudo systemctl restart datadog-agent

# Verify
sudo datadog-agent status | grep -A 15 mysql
```

---

## Part 4: PHP-FPM Integration

### 4.1 Enable PHP-FPM Status Page

```bash
# Edit PHP-FPM pool config (adjust version as needed)
sudo nano /etc/php/8.1/fpm/pool.d/www.conf
```

Find and uncomment/add:

```ini
pm.status_path = /php-fpm-status
ping.path = /php-fpm-ping
```

```bash
# Restart PHP-FPM
sudo systemctl restart php8.1-fpm
```

### 4.2 Configure Apache to Expose PHP-FPM Status

```bash
sudo nano /etc/apache2/conf-available/datadog-php-fpm.conf
```

```apache
<Location "/php-fpm-status">
    Require local
    Require ip 127.0.0.1
    ProxyPass "unix:/run/php/php8.1-fpm.sock|fcgi://localhost/php-fpm-status"
</Location>

<Location "/php-fpm-ping">
    Require local
    Require ip 127.0.0.1
    ProxyPass "unix:/run/php/php8.1-fpm.sock|fcgi://localhost/php-fpm-ping"
</Location>
```

```bash
# Enable proxy modules
sudo a2enmod proxy proxy_fcgi

# Enable config
sudo a2enconf datadog-php-fpm
sudo systemctl reload apache2

# Test
curl http://127.0.0.1/php-fpm-status?full
```

### 4.3 Configure Datadog PHP-FPM Integration

```bash
sudo nano /etc/datadog-agent/conf.d/php_fpm.d/conf.yaml
```

```yaml
init_config:

instances:
  - status_url: http://127.0.0.1/php-fpm-status?json
    ping_url: http://127.0.0.1/php-fpm-ping
    ping_reply: pong
    tags:
      - service:php-fpm
      - component:application
      - app:ojs
```

```bash
sudo systemctl restart datadog-agent
```

---

## Part 5: Log Collection

### 5.1 Enable Log Collection in Datadog Agent

```bash
sudo nano /etc/datadog-agent/datadog.yaml
```

Find and set:

```yaml
logs_enabled: true
```

### 5.2 Configure Apache Log Collection

```bash
sudo nano /etc/datadog-agent/conf.d/apache.d/conf.yaml
```

Add to existing config:

```yaml
logs:
  - type: file
    path: /var/log/apache2/access.log
    service: apache
    source: apache
    tags:
      - site:post45

  - type: file
    path: /var/log/apache2/error.log
    service: apache
    source: apache
    log_processing_rules:
      - type: multi_line
        name: apache_error_log
        pattern: \[[A-Za-z]{3}
    tags:
      - site:post45
```

### 5.3 Configure OJS Application Logs

```bash
sudo nano /etc/datadog-agent/conf.d/ojs.d/conf.yaml
```

```yaml
logs:
  - type: file
    path: /var/www/html/cache/*.log
    service: ojs
    source: php
    tags:
      - app:ojs
      - env:production

  - type: file
    path: /var/log/apache2/*submissions.post45.org*.log
    service: ojs
    source: apache
    tags:
      - app:ojs
      - env:production
```

### 5.4 Configure MySQL Logs

```bash
sudo nano /etc/datadog-agent/conf.d/mysql.d/conf.yaml
```

Add logs section:

```yaml
logs:
  - type: file
    path: /var/log/mysql/error.log
    service: mysql
    source: mysql

  - type: file
    path: /var/log/mysql/slow-query.log
    service: mysql
    source: mysql
    log_processing_rules:
      - type: multi_line
        name: mysql_slow_query
        pattern: "# Time:"
    tags:
      - db:ojs
```

### 5.5 Give Datadog Agent Permission to Read Logs

```bash
# Add datadog-agent user to adm group (can read /var/log)
sudo usermod -a -G adm dd-agent

# Set permissions for OJS logs
sudo chmod -R 755 /var/www/html/cache/
sudo chown -R www-data:adm /var/www/html/cache/

# Restart agent
sudo systemctl restart datadog-agent
```

---

## Part 6: Synthetic Monitoring (Uptime Checks)

### 6.1 Create Synthetic Tests in Datadog UI

**In Datadog web interface:**

1. Go to **Synthetic Monitoring → New Test → HTTP Test**

**Test 1: Main Site Uptime**
```
Name: Post45 Main Site - Homepage
URL: https://post45.org
Locations: Select 2-3 (e.g., N. Virginia, London, Singapore)
Frequency: Every 5 minutes
Assertions:
  - Status code is 200
  - Response time is less than 2000ms
  - Body contains "Post45"
Tags: service:post45-main, env:production
Alert conditions: Notify if failing from 2+ locations
```

**Test 2: OJS Homepage**
```
Name: OJS Submissions - Homepage
URL: https://submissions.post45.org
Locations: Same as above
Frequency: Every 5 minutes
Assertions:
  - Status code is 200
  - Response time is less than 3000ms
  - Body contains "submissions" or "Post45"
Tags: service:ojs, env:production
```

**Test 3: OJS Login Page**
```
Name: OJS Submissions - Login Functionality
URL: https://submissions.post45.org/login
Locations: Same as above
Frequency: Every 10 minutes
Assertions:
  - Status code is 200
  - Body contains "username" or "login"
Tags: service:ojs, component:auth, env:production
```

**Test 4: OJS Health Check**
```
Name: OJS - Health Check Endpoint
URL: https://submissions.post45.org/health-check.php
Frequency: Every 5 minutes
Assertions:
  - Status code is 200
  - JSON body status is "ok"
  - Response time < 1000ms
Tags: service:ojs, component:healthcheck
```

---

## Part 7: Mailgun Integration (Email Monitoring)

### 7.1 Create Mailgun Webhook Handler

Create a simple webhook endpoint to forward Mailgun events to Datadog:

```bash
sudo nano /var/www/html/mailgun-webhook.php
```

```php
<?php
/**
 * Mailgun Webhook Handler → Datadog Events
 * Receives Mailgun delivery events and forwards to Datadog
 */

// Mailgun webhook signing key (from Mailgun dashboard)
define('MAILGUN_WEBHOOK_SIGNING_KEY', 'your_webhook_signing_key_here');

// Datadog API key
define('DATADOG_API_KEY', 'your_datadog_api_key_here');

// Verify webhook signature
function verifyWebhookSignature($timestamp, $token, $signature) {
    $data = $timestamp . $token;
    $hash = hash_hmac('sha256', $data, MAILGUN_WEBHOOK_SIGNING_KEY);
    return hash_equals($hash, $signature);
}

// Get webhook data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

// Verify signature
if (!isset($data['signature'])) {
    http_response_code(401);
    exit('Invalid signature');
}

$sig = $data['signature'];
if (!verifyWebhookSignature($sig['timestamp'], $sig['token'], $sig['signature'])) {
    http_response_code(401);
    exit('Invalid signature');
}

// Extract event data
$eventData = $data['event-data'] ?? [];
$event = $eventData['event'] ?? 'unknown';
$recipient = $eventData['recipient'] ?? 'unknown';
$subject = $eventData['message']['headers']['subject'] ?? 'No subject';

// Map Mailgun events to Datadog severity
$severityMap = [
    'delivered' => 'info',
    'failed' => 'error',
    'rejected' => 'error',
    'complained' => 'warning',
    'unsubscribed' => 'warning',
    'opened' => 'info',
    'clicked' => 'info',
];

$severity = $severityMap[$event] ?? 'info';

// Send event to Datadog
$ddEvent = [
    'title' => "Mailgun: {$event}",
    'text' => "Email to {$recipient}: {$subject}",
    'alert_type' => $severity,
    'source_type_name' => 'mailgun',
    'tags' => [
        "event:{$event}",
        "recipient:{$recipient}",
        "service:mailgun",
        "env:production",
    ],
];

// Send to Datadog Events API
$ch = curl_init('https://api.datadoghq.com/api/v1/events');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'DD-API-KEY: ' . DATADOG_API_KEY,
    ],
    CURLOPT_POSTFIELDS => json_encode($ddEvent),
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Log failures
if ($httpCode !== 202) {
    error_log("Failed to send Mailgun event to Datadog: HTTP {$httpCode}");
}

http_response_code(200);
echo 'OK';
```

### 7.2 Configure Mailgun Webhooks

1. Log into **Mailgun Dashboard**: https://app.mailgun.com
2. Go to **Sending → Webhooks**
3. Select your domain: `post45.org`
4. Add webhook URL: `https://submissions.post45.org/mailgun-webhook.php`
5. Select events to track:
   - ✅ Permanent Failure
   - ✅ Temporary Failure
   - ✅ Rejected
   - ✅ Complained (spam)
   - ✅ Delivered (optional, can be noisy)
6. Test the webhook
7. Copy the **Webhook Signing Key** and update `mailgun-webhook.php`

### 7.3 Secure the Webhook Endpoint

```bash
# Restrict access to Mailgun IPs only
sudo nano /etc/apache2/conf-available/mailgun-webhook-security.conf
```

```apache
<Files "mailgun-webhook.php">
    # Mailgun webhook IPs (check current list at https://documentation.mailgun.com/en/latest/api-intro.html#webhooks)
    Require ip 52.61.0.0/16
    Require ip 52.32.0.0/16
    Require ip 35.180.0.0/16
    # Add more Mailgun IP ranges as needed
</Files>
```

```bash
sudo a2enconf mailgun-webhook-security
sudo systemctl reload apache2
```

---

## Part 8: Custom OJS Metrics

### 8.1 Create Custom Metrics Script

```bash
sudo nano /usr/local/bin/ojs-metrics.sh
```

```bash
#!/bin/bash
# OJS Custom Metrics → Datadog StatsD
# Runs every 5 minutes via cron

DD_API_KEY="your_datadog_api_key_here"
DB_NAME="ojs"
DB_USER="ojs"
DB_PASS="ojs"

# Function to send metric to Datadog
send_metric() {
    local metric_name=$1
    local value=$2
    local tags=$3

    curl -X POST "https://api.datadoghq.com/api/v1/series" \
        -H "Content-Type: application/json" \
        -H "DD-API-KEY: ${DD_API_KEY}" \
        -d "{
            \"series\": [{
                \"metric\": \"${metric_name}\",
                \"points\": [[$(date +%s), ${value}]],
                \"type\": \"gauge\",
                \"tags\": [${tags}]
            }]
        }" > /dev/null 2>&1
}

# Total submissions
TOTAL_SUBMISSIONS=$(mysql -u${DB_USER} -p${DB_PASS} -D${DB_NAME} -sN -e "SELECT COUNT(*) FROM submissions;")
send_metric "ojs.submissions.total" "${TOTAL_SUBMISSIONS}" "\"service:ojs\",\"env:production\""

# Active submissions (status = 1)
ACTIVE_SUBMISSIONS=$(mysql -u${DB_USER} -p${DB_PASS} -D${DB_NAME} -sN -e "SELECT COUNT(*) FROM submissions WHERE status=1;")
send_metric "ojs.submissions.active" "${ACTIVE_SUBMISSIONS}" "\"service:ojs\",\"env:production\""

# Submissions by stage
for stage in 1 2 3 4 5; do
    COUNT=$(mysql -u${DB_USER} -p${DB_PASS} -D${DB_NAME} -sN -e "SELECT COUNT(*) FROM submissions WHERE status=1 AND stage_id=${stage};")
    send_metric "ojs.submissions.by_stage" "${COUNT}" "\"service:ojs\",\"stage:${stage}\",\"env:production\""
done

# Published articles (status = 3)
PUBLISHED=$(mysql -u${DB_USER} -p${DB_PASS} -D${DB_NAME} -sN -e "SELECT COUNT(*) FROM submissions WHERE status=3;")
send_metric "ojs.submissions.published" "${PUBLISHED}" "\"service:ojs\",\"env:production\""

# Active users (logged in within last 30 days)
ACTIVE_USERS=$(mysql -u${DB_USER} -p${DB_PASS} -D${DB_NAME} -sN -e "SELECT COUNT(DISTINCT user_id) FROM user_user_groups WHERE date_start >= DATE_SUB(NOW(), INTERVAL 30 DAY);")
send_metric "ojs.users.active_30d" "${ACTIVE_USERS}" "\"service:ojs\",\"env:production\""

# Disk usage for OJS files directory
FILES_SIZE=$(du -sb /var/www/ojs-files | awk '{print $1}')
send_metric "ojs.storage.files_bytes" "${FILES_SIZE}" "\"service:ojs\",\"env:production\""

# Database size
DB_SIZE=$(mysql -u${DB_USER} -p${DB_PASS} -sN -e "SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema='${DB_NAME}';")
send_metric "ojs.storage.database_bytes" "${DB_SIZE}" "\"service:ojs\",\"env:production\""
```

```bash
# Make executable
sudo chmod +x /usr/local/bin/ojs-metrics.sh

# Test it
sudo /usr/local/bin/ojs-metrics.sh

# Add to cron (run every 5 minutes)
sudo crontab -e
```

Add:
```cron
*/5 * * * * /usr/local/bin/ojs-metrics.sh
```

---

## Part 9: Alerting & Monitors

### 9.1 Create Monitors in Datadog UI

**Monitor 1: High Error Rate**
```
Monitor Type: Metric
Metric: apache.net.hits
Condition: sum of apache.net.request_per_s by {status_code}
Alert threshold: status_code:5xx > 10 requests/min
Warning threshold: status_code:5xx > 5 requests/min
Message:
  {{#is_alert}}
  High error rate detected on OJS server
  {{value}} 5xx errors in the last minute
  @arthur@post45.org
  {{/is_alert}}
Tags: service:apache, env:production, priority:high
```

**Monitor 2: Site Down**
```
Monitor Type: Composite
Logic: (synthetic_check_post45_main failing) OR (synthetic_check_ojs failing)
Message:
  {{#is_alert}}
  🚨 Site is DOWN
  Post45 or OJS submissions site is unreachable
  @arthur@post45.org @pagerduty
  {{/is_alert}}
Tags: service:post45, env:production, priority:critical
```

**Monitor 3: High CPU Usage**
```
Monitor Type: Metric
Metric: system.cpu.user
Condition: avg of system.cpu.user over last 15 minutes
Alert threshold: > 80%
Warning threshold: > 60%
Message:
  {{#is_alert}}
  High CPU usage on OJS server
  Current: {{value}}%
  @arthur@post45.org
  {{/is_alert}}
```

**Monitor 4: Low Disk Space**
```
Monitor Type: Metric
Metric: system.disk.used
Condition: avg of system.disk.used over last 5 minutes
Alert threshold: > 80%
Warning threshold: > 70%
Message:
  {{#is_alert}}
  Low disk space on OJS server
  Current usage: {{value}}%
  @arthur@post45.org
  {{/is_alert}}
```

**Monitor 5: MySQL Connection Issues**
```
Monitor Type: Metric
Metric: mysql.net.connections
Condition: avg of mysql.net.connections
Alert when: no data for 5 minutes
Message:
  {{#is_alert}}
  MySQL monitoring stopped reporting
  Database may be down
  @arthur@post45.org
  {{/is_alert}}
```

**Monitor 6: Email Delivery Failures**
```
Monitor Type: Event
Event query: tags:event:failed,service:mailgun
Condition: > 5 events in 1 hour
Message:
  {{#is_alert}}
  High email failure rate detected
  {{event.count}} emails failed delivery in the last hour
  Check Mailgun dashboard for details
  @arthur@post45.org
  {{/is_alert}}
```

**Monitor 7: OJS Submission Spike (Anomaly Detection)**
```
Monitor Type: Anomaly
Metric: ojs.submissions.active
Algorithm: Basic (Agile)
Alert threshold: 3 standard deviations
Message:
  {{#is_alert}}
  Unusual submission activity detected
  This could indicate legitimate spike or potential issue
  Current: {{value}}, Expected: {{threshold}}
  @arthur@post45.org
  {{/is_alert}}
```

### 9.2 Configure Notification Channels

**In Datadog UI:**
1. Go to **Integrations → Integrations**
2. Configure notification methods:
   - **Email**: arthur@post45.org
   - **Slack** (optional): Create webhook, add Slack integration
   - **PagerDuty** (optional): For critical alerts

---

## Part 10: Dashboards

### 10.1 Create OJS Overview Dashboard

**In Datadog UI:**
1. Go to **Dashboards → New Dashboard**
2. Name: "Post45 OJS - Production Overview"
3. Add widgets:

**Widget 1: System Health (Timeseries)**
- Metrics: `system.cpu.user`, `system.mem.used`, `system.disk.used`
- Display: Line graph, 4-hour window

**Widget 2: Apache Requests (Timeseries)**
- Metric: `apache.net.request_per_s`
- Group by: `status_code`
- Display: Stacked area

**Widget 3: MySQL Performance (Timeseries)**
- Metrics: `mysql.performance.queries`, `mysql.performance.slow_queries`
- Display: Line graph

**Widget 4: OJS Submissions (Query Value)**
- Metric: `ojs.submissions.active`
- Display: Large number widget

**Widget 5: Submissions by Stage (Pie Chart)**
- Metric: `ojs.submissions.by_stage`
- Group by: `stage`
- Display: Pie chart

**Widget 6: Response Time (Heatmap)**
- Metric: Synthetic test response times
- Display: Heatmap by location

**Widget 7: Error Log Stream**
- Source: `apache` with `error` status
- Display: Log stream, last 50 entries

**Widget 8: Top Slow Queries (Top List)**
- Metric: `mysql.performance.query_run_time`
- Group by: `query`
- Display: Top 10 list

### 10.2 Create Email Monitoring Dashboard

**Dashboard: "Post45 - Email Delivery"**

Widgets:
1. **Delivery Success Rate** (timeseries) - Mailgun events
2. **Failed Deliveries** (event stream) - event:failed
3. **Email Volume** (query value) - Total emails sent (24h)
4. **Bounce Rate** (timeseries) - Failed/Total ratio

---

## Part 11: Maintenance & Best Practices

### 11.1 Regular Checks

**Weekly:**
- Review monitor alerts and tune thresholds
- Check dashboard for anomalies
- Verify all integrations are reporting

**Monthly:**
- Review log retention and adjust if needed
- Audit custom metrics (are they still useful?)
- Check Datadog bill (ensure within free tier limits)

**Quarterly:**
- Review and update alert notification channels
- Archive old dashboards
- Update documentation

### 11.2 Cost Management (Free Tier Limits)

Datadog free tier includes:
- ✅ Up to 5 hosts
- ✅ 1-day metric retention
- ✅ 15-month metrics at 1-hour resolution
- ⚠️ Limited log ingestion (500MB/day)
- ⚠️ Limited custom metrics (100 metrics)

**To stay within limits:**
- Use log sampling for high-volume logs
- Limit custom metrics to most important KPIs
- Use tag filtering to reduce cardinality
- Archive old logs to S3 if needed

### 11.3 Security Considerations

**Agent Configuration:**
- API keys stored in `/etc/datadog-agent/datadog.yaml` (root-only readable)
- Never commit API keys to git
- Rotate API keys annually

**Log Scrubbing:**
- Configure log scrubbing for sensitive data (passwords, tokens)
- Edit `/etc/datadog-agent/datadog.yaml`:

```yaml
logs_config:
  processing_rules:
    - type: mask_sequences
      name: mask_api_keys
      replace_placeholder: "[REDACTED]"
      pattern: (api_key|password|token)=\S+
```

**Network Security:**
- Datadog agent uses outbound HTTPS only (no inbound connections)
- Webhook endpoints should verify signatures
- Restrict webhook access by IP when possible

---

## Part 12: Troubleshooting

### 12.1 Agent Not Reporting

```bash
# Check agent status
sudo datadog-agent status

# Check agent logs
sudo tail -f /var/log/datadog/agent.log

# Restart agent
sudo systemctl restart datadog-agent

# Verify connectivity
sudo datadog-agent diagnose
```

### 12.2 Integration Not Working

```bash
# Check specific integration
sudo datadog-agent status | grep -A 20 apache
sudo datadog-agent status | grep -A 20 mysql

# Test integration manually
sudo -u dd-agent datadog-agent check apache
sudo -u dd-agent datadog-agent check mysql

# Check integration logs
sudo tail -f /var/log/datadog/integrations.log
```

### 12.3 Logs Not Appearing

```bash
# Verify log collection is enabled
grep logs_enabled /etc/datadog-agent/datadog.yaml

# Check log agent status
sudo datadog-agent status | grep -A 10 "Logs Agent"

# Verify file permissions
sudo -u dd-agent cat /var/log/apache2/error.log

# Check log processing
sudo datadog-agent log-agent status
```

### 12.4 Custom Metrics Not Showing

```bash
# Test custom metrics script manually
sudo /usr/local/bin/ojs-metrics.sh

# Check API response
curl -X POST "https://api.datadoghq.com/api/v1/validate" \
  -H "DD-API-KEY: your_api_key"

# Verify metrics in Datadog UI (Metrics Explorer)
# Search for: ojs.submissions.*
```

---

## Part 13: Quick Reference

### Key Commands

```bash
# Agent management
sudo systemctl status datadog-agent
sudo systemctl restart datadog-agent
sudo datadog-agent status
sudo datadog-agent diagnose

# View specific integration status
sudo datadog-agent status | grep apache
sudo datadog-agent status | grep mysql
sudo datadog-agent status | grep php_fpm

# Test integrations
sudo -u dd-agent datadog-agent check apache
sudo -u dd-agent datadog-agent check mysql

# View logs
sudo tail -f /var/log/datadog/agent.log
sudo tail -f /var/log/datadog/integrations.log

# Force flush metrics
sudo datadog-agent flare
```

### Important Files

```
/etc/datadog-agent/datadog.yaml           # Main config
/etc/datadog-agent/conf.d/apache.d/       # Apache integration
/etc/datadog-agent/conf.d/mysql.d/        # MySQL integration
/etc/datadog-agent/conf.d/php_fpm.d/      # PHP-FPM integration
/etc/datadog-agent/conf.d/ojs.d/          # Custom OJS config
/var/log/datadog/                         # Agent logs
/usr/local/bin/ojs-metrics.sh             # Custom metrics script
/var/www/html/mailgun-webhook.php         # Mailgun webhook handler
```

### Useful Datadog URLs

```
Dashboard: https://app.datadoghq.com/dashboard/lists
Metrics Explorer: https://app.datadoghq.com/metric/explorer
Log Explorer: https://app.datadoghq.com/logs
Monitors: https://app.datadoghq.com/monitors/manage
Synthetic Tests: https://app.datadoghq.com/synthetics/list
APM: https://app.datadoghq.com/apm/home
Infrastructure: https://app.datadoghq.com/infrastructure
```

---

## Next Steps

1. ✅ Install Datadog Agent
2. ✅ Configure system integrations (Apache, MySQL, PHP-FPM)
3. ✅ Set up log collection
4. ✅ Create synthetic monitoring tests
5. ✅ Configure Mailgun webhook integration
6. ✅ Deploy custom OJS metrics
7. ✅ Create monitors and alerts
8. ✅ Build dashboards
9. ⏭️ Test alerting by triggering a test failure
10. ⏭️ Document runbooks for common issues

---

## Support Resources

- **Datadog Docs**: https://docs.datadoghq.com
- **OJS Documentation**: https://docs.pkp.sfu.ca
- **Mailgun API**: https://documentation.mailgun.com
- **Apache mod_status**: https://httpd.apache.org/docs/2.4/mod/mod_status.html
- **MySQL Performance Schema**: https://dev.mysql.com/doc/refman/8.0/en/performance-schema.html

---

**Last Updated**: 2025-01-13
**Maintained By**: Arthur Wang (@arthurwang)
**Environment**: Production (Post45 OJS)
