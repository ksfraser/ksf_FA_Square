BABOK — ksf_fa_downloader (Module Download Service)
==========================================
Business Requirements (BR):
- FR-DL.01: Set repository credentials (syspref/repo_auth -> version.php) for module source downloads
- FR-DL.02: Manage known modules and their GitHub tags (table: 0_fa_download_modules)
- FR-DL.03: Check for new module versions (table: 0_fa_download_targets / 0_fa_downloads)
- FR-DL.04: Download/install modules into /modules/ directory

Use Cases:
- UC-DL.01: Configure repo auth (GitHub user/token or SSH key path)
- UC-DL.02: Check for new versions of installed modules
- UC-DL.03: Download selected modules (checkbox + download button)
- UC-DL.04: View download/status history

Functional Requirements (FR):
- Table 0_fa_repo_auth: repo_url, github_user, github_token, ssh_key_path, active
- Table 0_fa_download_modules: module_name, github_repo, latest_tag, installed_version, status, download_path
- Table 0_fa_download_targets / 0_fa_downloads (existing): module download tracking
- Admin UI: Setup app pages (targets, search, history) with config/edit forms
- Hooks: Download service responds to module requests; no direct cross-module DAO
