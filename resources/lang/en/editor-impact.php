<?php

declare(strict_types=1);

return [
    'html_label' => 'HTML cache',
    'html_instant' => 'Changing this record invalidates these URLs immediately. Counts include only sites you can view; up to 10 URLs are shown.',
    'html_scheduled' => 'Changing this record marks these tracked URLs stale for scheduled refresh. Counts include only sites you can view; up to 10 URLs are shown.',
    'html_layout' => 'Saving this layout invalidates the affected site cache immediately, including in scheduled mode. Counts include only sites you can view; up to 10 URLs are shown.',
    'translation_label' => 'Translated-content cache, if edited',
    'translation_description' => 'Additional tracked URLs may be affected when existing page translations change. This is conditional scope, excluding URLs already counted above; unchanged translations do not invalidate these entries.',
    'estimate_unavailable' => 'Timing unavailable: no usable completed generation history for the affected sites.',
    'estimate_basis' => 'Approximate regeneration time: :affected affected URLs × :seconds seconds from a completed generation ÷ :total currently tracked URLs on those sites. Current URL count is a proxy for the historical workload. Does not predict future queue wait or manual search work.',
];
