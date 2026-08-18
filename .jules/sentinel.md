## 2026-04-16 - Unauthenticated Database Upload
**Vulnerability:** The `main/upload_db.php` script allowed unauthenticated users to upload and overwrite the entire SQLite database file (`database.sqlite`).
**Learning:** Admin endpoints and critical data manipulation scripts must be guarded with session validation and role checks. Leaving maintenance scripts open is a critical threat.
**Prevention:** Always verify `$_SESSION['user_id']` and confirm the user's role against the database before executing any administrative action or file upload processing.

## 2024-04-16 - Hardcoded Secrets
**Vulnerability:** Hardcoded API keys in `main/cloudinary.php` and plaintext passwords in `main/gensql.php`.
**Learning:** Hardcoded credentials can easily leak and compromise the entire system.
**Prevention:** Rely on environment variables for API keys and remove setup scripts containing sensitive test/init data from the production codebase.

## 2024-04-15 - Unauthenticated Database Upload Vulnerability
**Vulnerability:** Found an unauthenticated file upload vulnerability in `main/upload_db.php`. Anyone could upload an arbitrary `.sqlite` file to overwrite the application's entire database, allowing complete takeover of application data.
**Learning:** Admin tools or developer scripts intended for convenience (like `upload_db.php`) are sometimes left completely unprotected in production or without any authentication checks, representing a severe security risk. This application relies on a session-based role check for other admin pages, but missed it here.
**Prevention:** Ensure all files accessible via the web server enforce proper authentication and authorization checks. Centralize admin routing if possible to prevent individual files from being exposed without checks.

## 2024-05-12 - Stored XSS in Tactic Positions
**Vulnerability:** Found a Stored XSS vulnerability in `main/pizarra.php` where the JSON payload for tactic positions was fetched from the database and directly echoed into an inline JavaScript block without encoding/escaping.
**Learning:** Database data, even when expected to be structured like JSON, should never be trusted as safe for raw HTML or inline JS execution. `echo`ing raw strings into `<script>` blocks allows easy payload breakout.
**Prevention:** Always use `json_encode` with safety flags (`JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP`) when embedding PHP variables inside inline JavaScript contexts.

## 2024-05-13 - Session Security Fixes
**Vulnerability:** Session cookies lacked security flags (`httponly`, `secure`, `samesite`), making them vulnerable to XSS and CSRF. The authentication system also lacked `session_regenerate_id(true)` upon successful login, leading to a session fixation risk.
**Learning:** Proper session management is critical. Cookies should always be secured, and session IDs must be rotated after authentication to prevent attackers from reusing an established session ID.
**Prevention:** Always use `session_set_cookie_params` with `httponly => true`, `secure => true`, and `samesite => 'Lax'`. Always call `session_regenerate_id(true)` immediately after successful user authentication to mitigate session fixation.
