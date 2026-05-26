<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Polaris fork — Bearer JWT auth hook (S3-5).
 *
 * Fires at pre_controller for every request to /api/v1/*.
 * Decodes the Polaris-issued JWT, validates claims, and populates
 * MY_Controller::$tenant_id so that MY_Model auto-scoping works for the
 * entire request without any per-controller auth call.
 *
 * Registered in application/config/hooks.php under the 'pre_controller' hook.
 * ---------------------------------------------------------------------------- */

class BearerAuthHook
{
    /**
     * Validate the Bearer token for API routes.
     *
     * Called by CI3's hook runner before the target controller is constructed.
     * Returns immediately for non-API routes.
     */
    public function validate(): void
    {
        // MY_Controller is in application/core/ but CI3 doesn't autoload core
        // files until the controller is instantiated — too late for pre_controller.
        if (!class_exists('MY_Controller', false)) {
            require_once APPPATH . 'core/MY_Controller.php';
        }

        // Clear stale static context first — PHP-FPM reuses worker processes,
        // so a previous request's tenant could leak into this one if we skip this.
        MY_Controller::clear_tenant_context();

        // get_instance() returns null at pre_controller time (CI superobject is not
        // yet assigned). Parse REQUEST_URI directly instead.
        $raw_uri = ltrim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/');
        // Strip the index.php front-controller segment if present.
        if (str_starts_with($raw_uri, 'index.php/')) {
            $raw_uri = substr($raw_uri, strlen('index.php/'));
        } elseif ($raw_uri === 'index.php') {
            $raw_uri = '';
        }

        // Only enforce on the REST API surface.
        if (!str_starts_with($raw_uri, 'api/v1/') && $raw_uri !== 'api/v1') {
            return;
        }

        // Internal Polaris-to-EA endpoints use HMAC-SHA256, not Bearer JWT.
        if (str_starts_with($raw_uri, 'api/v1/internal/')) {
            return;
        }

        $token = $this->extract_bearer_token();

        if ($token === null) {
            $this->respond_401('token_missing');
            // respond_401 exits — unreachable
        }

        try {
            MY_Controller::from_bearer($token);
        } catch (RuntimeException $e) {
            $this->respond_401($e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function extract_bearer_token(): ?string
    {
        // HTTP_AUTHORIZATION is the standard CGI/FastCGI variable.
        // Fall back to the Apache header bag (case-insensitive lookup).
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

        if ($header === null && function_exists('apache_request_headers')) {
            $apache = array_change_key_case(apache_request_headers(), CASE_LOWER);
            $header = $apache['authorization'] ?? null;
        }

        // Cap token length before any decode attempt — maliciously large
        // payloads could exhaust memory inside the JWT library.
        if ($header !== null && strlen($header) > 8192) {
            return null;
        }

        if ($header !== null && preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Emit a JSON 401 response and terminate the request.
     * The $code is one of: token_missing | token_expired | token_invalid.
     * No internal detail is forwarded to the caller.
     */
    private function respond_401(string $code): never
    {
        header('Content-Type: application/json', true, 401);
        echo json_encode(['code' => 401, 'message' => $code], JSON_UNESCAPED_UNICODE);
        exit(1);
    }
}
