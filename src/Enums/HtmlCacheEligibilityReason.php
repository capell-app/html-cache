<?php

declare(strict_types=1);

namespace Capell\HtmlCache\Enums;

enum HtmlCacheEligibilityReason: string
{
    case NonGetRequest = 'non_get_request';
    case QueryStringPresent = 'query_string_present';
    case SignedPreviewRequest = 'signed_preview_request';
    case AuthenticatedOrSessionRequest = 'authenticated_or_session_request';
    case LivewireRequest = 'livewire_request';
    case InertiaRequest = 'inertia_request';
    case AuthorizationHeaderPresent = 'authorization_header_present';
    case ConfiguredBypassRule = 'configured_bypass_rule';
    case CacheBypassResolver = 'cache_bypass_resolver';
    case ExplicitCacheBypass = 'explicit_cache_bypass';
    case UnsafeRequestPath = 'unsafe_request_path';
    case SessionUserState = 'session_user_state';
    case PublicHtmlInspectionFailed = 'public_html_inspection_failed';
    case CacheDisabled = 'cache_disabled';
    case CacheWriteDisabled = 'cache_write_disabled';
    case UnsafePublicOutput = 'unsafe_public_output';
    case BakedSessionToken = 'baked_session_token';
    case BakedSessionTokenInspectorUnavailable = 'baked_session_token_inspector_unavailable';
    case NonHtmlResponse = 'non_html_response';
    case UncacheableResponseStatus = 'uncacheable_response_status';
    case ResponseNoStore = 'response_no_store';
    case ResponsePrivate = 'response_private';
    case ResponseNoCache = 'response_no_cache';
    case ResponseSetsCookie = 'response_sets_cookie';
    case UnsupportedVaryHeader = 'unsupported_vary_header';
    case FrontendContextNotCacheable = 'frontend_context_not_cacheable';
    case PackageCacheBlocking = 'package_cache_blocking';
    case PackageSensitiveOutput = 'package_sensitive_output';
    case StaleClaimInvalid = 'stale_claim_invalid';
    case MissingSiteDomain = 'missing_site_domain';
    case RedirectUrl = 'redirect_url';
    case UnpublishedPage = 'unpublished_page';

    public static function acceptsRefreshStatus(int $responseStatus): bool
    {
        return ($responseStatus >= 200 && $responseStatus < 300)
            || in_array($responseStatus, [301, 308], true);
    }

    public function isDeterministicForStaleRefresh(int $responseStatus): bool
    {
        // A failure or temporary redirect says nothing about the URL's lasting cache policy.
        if (! self::acceptsRefreshStatus($responseStatus)) {
            return false;
        }

        if ($responseStatus >= 300) {
            return $this === self::RedirectUrl;
        }

        return match ($this) {
            self::NonHtmlResponse,
            self::ResponsePrivate,
            self::ResponseNoStore,
            self::ConfiguredBypassRule,
            self::PackageCacheBlocking,
            self::PackageSensitiveOutput,
            self::RedirectUrl => true,
            default => false,
        };
    }
}
