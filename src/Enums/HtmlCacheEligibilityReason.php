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
    case CacheDisabled = 'cache_disabled';
    case CacheWriteDisabled = 'cache_write_disabled';
    case UnsafePublicOutput = 'unsafe_public_output';
    case NonHtmlResponse = 'non_html_response';
    case UncacheableResponseStatus = 'uncacheable_response_status';
    case ResponseNoStore = 'response_no_store';
    case FrontendContextNotCacheable = 'frontend_context_not_cacheable';
    case PackageCacheBlocking = 'package_cache_blocking';
    case PackageSensitiveOutput = 'package_sensitive_output';
    case StaleClaimInvalid = 'stale_claim_invalid';
    case MissingSiteDomain = 'missing_site_domain';
    case RedirectUrl = 'redirect_url';
    case UnpublishedPage = 'unpublished_page';
}
