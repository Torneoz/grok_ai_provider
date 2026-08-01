# Security policy

## Supported releases

Security fixes are provided for the latest tagged release on the 1.x branch.
Pre-release users should upgrade to the newest alpha or beta before reporting
an issue that may already have been corrected.

## Reporting a vulnerability

Do not open a public issue containing API keys, prompts, uploaded media,
customer data, internal hostnames, or exploit details. Use GitHub's private
security-advisory reporting for the Torneoz/grok_ai_provider repository. If
private reporting is unavailable, contact the project maintainers through the
private contact method published by the Torneoz project.

Include the affected module, Drupal, Drupal AI, PHP, and HTTP-client versions;
the configured transport; reproduction steps; and the smallest sanitized log
extract needed to understand the issue. Revoke any credential that may have
been disclosed.

## Security boundaries

Administrators can select a custom HTTPS API endpoint. That endpoint receives
the selected xAI API key and request content and must therefore be trusted.
Generated-video downloads are restricted to xAI asset hosts or that configured
gateway host. Site-level permissions for hosted tools do not replace access
control on Drupal content supplied to a prompt.
