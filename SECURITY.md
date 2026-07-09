# Security Policy

## Supported Versions

Currently, the `latest` branch (Week 7+ refactor) is actively supported for security updates.

| Version | Supported          |
| ------- | ------------------ |
| 2.x     | :white_check_mark: |
| 1.x     | :x:                |

## Reporting a Vulnerability

If you discover a security vulnerability within Nimbus Cloud Transfer, please do not disclose it publicly. Instead, send an email detailing the vulnerability to the project maintainers. We take all security issues seriously and will respond within 48 hours to acknowledge the report and outline a remediation timeline.

## Recent Security Enhancements (v2.x)

Following a technical assessment of the legacy 1.x proof-of-concept codebase, the following critical vulnerabilities have been formally mitigated in the v2.x refactor:

1. **Cleartext Credentials Exposure**: Passwords and OAuth tokens are no longer exposed in browser network devtools or logged in plaintext. The upload requests utilize securely transmitted POST bodies, and background workers process credentials asynchronously from an internal database.
2. **Path Traversal Vulnerability**: Input validation has been added to sanitize the `uploadDir` field.
3. **Server-Side Request Forgery (SSRF)**: Validation logic has been implemented to ensure URLs provided to the daemon do not resolve to local or private IP addresses.
4. **Denial of Service (Resource Exhaustion)**: A backend job queue system (SQLite/Redis) guarantees that PHP processes are not blocked or exhausted by thousands of parallel requests. Timeouts have been effectively bypassed using asynchronous workers.
5. **Data Reliability**: Silent failures are now caught and explicitly reported to the user dashboard using proper cURL multi-handle error inspections.

For more details on operations and downtime recovery, refer to our `INCIDENT_RESPONSE.md`.
