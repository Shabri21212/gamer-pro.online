<?php
declare(strict_types=1);

const ICON_PATHS = [
    'home' => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-6a2 2 0 0 1 2.582 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
    'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
    'moon' => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
    'sun-moon' => '<path d="M12 8a2.83 2.83 0 0 0 4 4 4 4 0 1 1-4-4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M6.3 17.7l-1.4 1.4M19.1 4.9l-1.4 1.4"/>',
    'calendar' => '<path d="M8 2v4M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
    'calendar-plus' => '<path d="M8 2v4M16 2v4"/><path d="M21 13V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8"/><path d="M3 10h18M16 19h6M19 16v6"/>',
    'copy' => '<rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
    'keyboard' => '<path d="M10 8h.01M12 12h.01M14 8h.01M16 12h.01M18 8h.01M6 8h.01M7 16h10M8 12h.01"/><rect width="20" height="16" x="2" y="4" rx="2"/>',
    'clipboard-check' => '<rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>',
    'clipboard-list' => '<rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4M12 16h4M8 11h.01M8 16h.01"/>',
    'wrench' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
    'alert' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4M12 17h.01"/>',
    'megaphone' => '<path d="m3 11 18-5v12L3 14v-3Z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
    'star' => '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>',
    'percent' => '<path d="M19 5 5 19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
    'coins' => '<circle cx="8" cy="8" r="6"/><path d="M18.09 10.37A6 6 0 1 1 10.34 18"/><path d="M7 6h1v4M16.71 13.88l.7.71-2.82 2.82"/>',
    'gift' => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"/>',
    'cake' => '<path d="M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8"/><path d="M4 16s.5-1 2-1 2.5 2 4 2 2.5-2 4-2 2.5 2 4 2 2-1 2-1"/><path d="M2 21h20M7 8v3M12 8v3M17 8v3M7 4h.01M12 4h.01M17 4h.01"/>',
    'book' => '<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
    'tags' => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
    'scale' => '<path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M7 21h10M12 3v18M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/>',
    'grad' => '<path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>',
    'trophy' => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18M4 22h16M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>',
    'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M22 11h-6"/>',
    'history' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5M12 7v5l4 2"/>',
    'wallet' => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2.3"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
    'key' => '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>',
    'shield' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    'building' => '<path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2M10 6h4M10 10h4M10 14h4M10 18h4"/>',
    'menu' => '<path d="M4 5h16M4 12h16M4 19h16"/>',
    'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
    'chevrondown' => '<path d="m6 9 6 6 6-6"/>',
    'chevronup' => '<path d="m18 15-6-6-6 6"/>',
    'chevronleft' => '<path d="m15 18-6-6 6-6"/>',
    'chevronright' => '<path d="m9 18 6-6-6-6"/>',
    'trash' => '<path d="M3 6h18M8 6V4h8v2M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6M10 11v6M14 11v6"/>',
    'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
    'arrow' => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
    'arrow-left' => '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
    'arrow-up-right' => '<path d="M7 7h10v10"/><path d="M7 17 17 7"/>',
    'external' => '<path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    'eye' => '<path d="M2.06 12.35a1 1 0 0 1 0-.7C3.87 7.5 7.6 4.5 12 4.5s8.13 3 9.94 7.15a1 1 0 0 1 0 .7C20.13 16.5 16.4 19.5 12 19.5S3.87 16.5 2.06 12.35Z"/><circle cx="12" cy="12" r="3"/>',
    'eye-off' => '<path d="M10.7 5.1A10.9 10.9 0 0 1 12 5c7 0 10 7 10 7a13.4 13.4 0 0 1-1.7 2.5"/><path d="M6.6 6.6C3.5 8.7 2 12 2 12s3 7 10 7a9.8 9.8 0 0 0 5.4-1.6"/><path d="M14.1 14.1a3 3 0 1 1-4.2-4.2M2 2l20 20"/>',
    'pencil' => '<path d="M21.2 6.4a2.4 2.4 0 0 0-3.4-3.4L5 15.8 3 21l5.2-2Z"/><path d="m15 5 4 4"/>',
    'check' => '<path d="M20 6 9 17l-5-5"/>',
    'check-circle' => '<path d="M21.8 10A10 10 0 1 1 17 3.3"/><path d="m9 11 3 3L22 4"/>',
    'plus' => '<path d="M5 12h14M12 5v14"/>',
    'minus' => '<path d="M5 12h14"/>',
    'pin' => '<path d="M20 10c0 4.4-8 12-8 12S4 14.4 4 10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
    'pushpin' => '<path d="M12 17v5M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/>',
    'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    'timer' => '<path d="M10 2h4M12 14l3-3"/><circle cx="12" cy="14" r="8"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
    'filter' => '<path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>',
    'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    'list-ordered' => '<path d="M10 12h11M10 18h11M10 6h11M4 10h2M4 6h1v4M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/>',
    'more' => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
    'lock' => '<rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    'phone' => '<path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"/>',
    'monitor' => '<rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8M12 17v4"/>',
    'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
    'save' => '<path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7M7 3v4a1 1 0 0 0 1 1h7"/>',
    'send' => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
    'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
    'bell' => '<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>',
    'file-text' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4M10 9H8M16 13H8M16 17H8"/>',
    'sliders' => '<path d="M21 4h-7M10 4H3M21 12h-9M8 12H3M21 20h-5M12 20H3M14 2v4M8 10v4M16 18v4"/>',
    'refresh' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
    'bold' => '<path d="M6 12h9a4 4 0 0 1 0 8H7a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h7a4 4 0 0 1 0 8"/>',
    'italic' => '<path d="M19 4h-9M14 20H5M15 4 9 20"/>',
    'heading' => '<path d="M4 12h8M4 18V6M12 18V6"/><path d="M21 18h-4c0-4 4-3 4-6 0-1.5-2-2.5-4-1"/>',
    'quote' => '<path d="M16 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1a2 2 0 0 1-2 2 1 1 0 0 0-1 1v2a1 1 0 0 0 1 1 6 6 0 0 0 6-6V5a2 2 0 0 0-2-2zM5 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1a2 2 0 0 1-2 2 1 1 0 0 0-1 1v2a1 1 0 0 0 1 1 6 6 0 0 0 6-6V5a2 2 0 0 0-2-2z"/>',
    'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    'code' => '<path d="m16 18 6-6-6-6M8 6l-6 6 6 6"/>',
    'table' => '<path d="M12 3v18"/><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18M3 15h18"/>',
    'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
    'download' => '<path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/>',
];

function icon(string $name, string $class = 'size-4'): string
{
    $d = ICON_PATHS[$name] ?? ICON_PATHS['info'];
    return '<svg class="' . h($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $d . '</svg>';
}

function brand_logo(string $class = 'h-4 w-auto', bool $full = false): string
{
    $view = $full ? '0 0 870 119' : '0 0 870 67';
    $gamer = '<path d="M30.8997 66.0082L23.4753 58.5863V49.2359H72.7278V41.7656H38.1802V24.9921H89.4988V53.3057L76.7937 66.0082H30.8997Z M0 66.0082V28.3149L27.5642 0.750732H89.4988V10.0985L82.0756 17.523H33.5904L16.771 34.3424V58.5863L9.34781 66.0082H0Z M180.007 66.0082L156.371 17.523H141.441L128.908 43.1727H161.796V52.5256L154.371 59.9488H120.75L117.801 66.0082H98.9423L131.007 0.750732H166.81L198.684 66.0082H180.007Z M327.19 66.0082V0.750732H416.689V10.0985L409.266 17.523H343.735V24.992H410.629V41.7656H343.735V49.2359H409.267L416.689 56.6591V66.0082H327.19Z M299.712 66.0082V27.1041L265.644 44.1899H258.829L224.894 27.1041L224.754 66.0082H208.137V0L262.24 27.1423L316.338 0V66.0082H299.712Z M508.613 66.0541L492.477 41.7656H452.994V24.992H492.506L497.469 17.523H444.313V66.0082H427.539V0.750732H527.239L505.558 33.3814L527.234 66.0541H508.613Z" fill="currentColor"/>';
    $pro = '<path d="M801.514 66.0082L780.499 44.9929V0.750732H848.986L870 21.7673V66.0069L801.514 66.0082Z M853.227 49.2359V30.5276L840.222 17.523H797.274V36.2313L810.278 49.2372H853.227V49.2359Z M753.035 66.0541L736.898 41.7656H697.416V24.992H736.927L741.89 17.523H688.734V66.0082H671.961V0.750732H771.661L749.98 33.3814L771.657 66.0541H753.035Z M563.425 66.0082V0.750732H663.122L640.589 34.6661L635.53 41.7656H588.879V24.992H628.391L633.354 17.523H580.196V66.0082H563.425Z" fill="var(--brand)"/>';
    return '<svg viewBox="' . $view . '" class="' . h($class) . '" role="img" aria-label="GAMER PRO">' . $gamer . $pro . '</svg>';
}

function btn_class(string $variant = 'primary', string $size = 'md'): string
{
    $base = 'inline-flex shrink-0 cursor-pointer items-center justify-center gap-2 whitespace-nowrap rounded-md font-semibold transition-colors outline-none select-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:pointer-events-none disabled:opacity-50 [&_svg]:shrink-0';
    $v = [
        'primary' => 'bg-primary text-primary-foreground shadow-xs hover:bg-primary/90',
        'ink' => 'bg-foreground text-background hover:bg-foreground/85',
        'secondary' => 'bg-secondary text-secondary-foreground hover:bg-secondary/70',
        'outline' => 'border border-border bg-card text-foreground shadow-xs hover:bg-muted',
        'ghost' => 'text-foreground hover:bg-muted',
        'quiet' => 'text-muted-foreground hover:bg-muted hover:text-foreground',
        'danger' => 'border border-primary/30 bg-card text-primary-ink hover:bg-primary/10',
        'danger-ghost' => 'text-muted-foreground hover:bg-primary/10 hover:text-primary-ink',
        'link' => 'h-auto px-0 text-primary-ink underline-offset-4 hover:underline',
    ][$variant] ?? 'bg-primary text-primary-foreground';
    $s = [
        'xs' => 'h-7 px-2.5 text-xs',
        'sm' => 'h-8 px-3 text-sm',
        'md' => 'h-10 px-4 text-sm',
        'lg' => 'h-12 px-5 text-base',
        'icon' => 'size-10 p-0',
        'icon-sm' => 'size-8 p-0',
    ][$size] ?? 'h-10 px-4 text-sm';
    if ($variant === 'link') {
        $s = 'text-sm';
    }
    return "$base $v $s";
}

function field_class(string $extra = ''): string
{
    return trim('block h-10 w-full min-w-0 rounded-md border border-input bg-card px-3 text-base text-foreground shadow-xs transition-[border-color,box-shadow] outline-none placeholder:text-muted-foreground/75 focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/20 disabled:cursor-not-allowed disabled:opacity-60 read-only:bg-muted/50 sm:text-sm ' . $extra);
}

function select_class(string $extra = ''): string
{
    return field_class('gp-select pr-9 ' . $extra);
}

function textarea_class(string $extra = ''): string
{
    return field_class('h-auto min-h-24 py-2.5 leading-relaxed ' . $extra);
}

function check_class(string $extra = ''): string
{
    return trim('size-4 shrink-0 cursor-pointer accent-primary ' . $extra);
}

function form_open(string $action, string $attrs = ''): string
{
    return '<form method="post"' . ($attrs !== '' ? ' ' . $attrs : '') . '>' . csrf_field() . '<input type="hidden" name="_action" value="' . h($action) . '">';
}

function hidden(string $name, string|int|null $value): string
{
    return '<input type="hidden" name="' . h($name) . '" value="' . h((string) $value) . '">';
}

function confirm_attr(string $message): string
{
    return 'data-confirm="' . h($message) . '"';
}

/** Подпись + поле внутри ������дного <label>, чтобы клик по подписи фокусировал поле. */
function ui_field(string $label, string $control, string $hint = '', string $class = ''): string
{
    $html = '<label class="flex min-w-0 flex-col gap-1.5 ' . h($class) . '"><span class="text-sm font-medium text-foreground">' . h($label) . '</span>' . $control;
    if ($hint !== '') {
        $html .= '<span class="text-xs leading-relaxed text-muted-foreground">' . h($hint) . '</span>';
    }
    return $html . '</label>';
}

/**
 * $options: [value => label] или [groupLabel => [value => label]] для optgroup.
 */
function ui_select(string $name, array $options, string|int|null $selected = null, string $attrs = '', string $class = ''): string
{
    $sel = $selected === null ? null : (string) $selected;
    $html = '<select name="' . h($name) . '" class="' . h(select_class($class)) . '"' . ($attrs !== '' ? ' ' . $attrs : '') . '>';
    foreach ($options as $value => $label) {
        if (is_array($label)) {
            $html .= '<optgroup label="' . h((string) $value) . '">';
            foreach ($label as $v => $l) {
                $html .= '<option value="' . h((string) $v) . '"' . ($sel !== null && (string) $v === $sel ? ' selected' : '') . '>' . h((string) $l) . '</option>';
            }
            $html .= '</optgroup>';
            continue;
        }
        $html .= '<option value="' . h((string) $value) . '"' . ($sel !== null && (string) $value === $sel ? ' selected' : '') . '>' . h((string) $label) . '</option>';
    }
    return $html . '</select>';
}

function eyebrow(string $text): string
{
    return '<p class="flex items-center gap-2 text-[11px] font-semibold tracking-[0.16em] text-muted-foreground uppercase"><span class="cut-mark" aria-hidden="true"></span>' . h($text) . '</p>';
}

/** Шапка страницы. $eyebrow = '' — подставить раздел меню, null — без надзаголовка. */
function page_head(string $title, string $lead = '', string $actions = '', ?string $eyebrow = ''): string
{
    if ($eyebrow === '') {
        $item = find_nav_item(request_path());
        $eyebrow = $item['group'] ?? null;
    }
    $html = '<header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">';
    $html .= '<div class="flex min-w-0 flex-col gap-2">';
    if ($eyebrow) {
        $html .= eyebrow($eyebrow);
    }
    $html .= '<h1 class="font-display text-2xl leading-tight font-semibold tracking-tight text-balance sm:text-[1.9rem]">' . h($title) . '</h1>';
    if ($lead !== '') {
        $html .= '<p class="max-w-2xl text-sm leading-relaxed text-pretty text-muted-foreground">' . $lead . '</p>';
    }
    $html .= '</div>';
    if ($actions !== '') {
        $html .= '<div class="flex flex-wrap items-center gap-2">' . $actions . '</div>';
    }
    return $html . '</header>';
}

function card(string $inner, string $class = '', string $tag = 'section', string $attrs = ''): string
{
    return '<' . $tag . ' class="surface rounded-lg border border-border bg-card text-card-foreground shadow-xs ' . h($class) . '"' . ($attrs !== '' ? ' ' . $attrs : '') . '>' . $inner . '</' . $tag . '>';
}

function card_head(string $title, string $meta = '', string $actions = '', string $iconName = '', string $class = ''): string
{
    $html = '<div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 ' . h($class) . '"><div class="flex min-w-0 items-start gap-3">';
    if ($iconName !== '') {
        $html .= '<span class="icon-tile mt-0.5 size-8">' . icon($iconName, 'size-4') . '</span>';
    }
    $html .= '<div class="flex min-w-0 flex-col gap-0.5"><h2 class="text-base leading-snug font-semibold text-pretty">' . h($title) . '</h2>';
    if ($meta !== '') {
        $html .= '<p class="text-sm leading-relaxed text-muted-foreground">' . $meta . '</p>';
    }
    $html .= '</div></div>';
    if ($actions !== '') {
        $html .= '<div class="flex flex-wrap items-center gap-2">' . $actions . '</div>';
    }
    return $html . '</div>';
}

function section_title(string $title, string $right = '', string $count = ''): string
{
    $html = '<div class="flex flex-wrap items-center justify-between gap-3"><h2 class="flex items-center gap-2 text-sm font-semibold">' . h($title);
    if ($count !== '') {
        $html .= '<span class="rounded-full bg-muted px-2 py-0.5 text-xs font-semibold text-muted-foreground tabular-nums">' . h($count) . '</span>';
    }
    $html .= '</h2>';
    if ($right !== '') {
        $html .= '<div class="flex flex-wrap items-center gap-2">' . $right . '</div>';
    }
    return $html . '</div>';
}

const CHIP_TONES = [
    'neutral' => 'bg-muted text-muted-foreground',
    'day' => 'bg-day text-day-foreground',
    'night' => 'bg-night text-night-foreground',
    'ink' => 'bg-foreground text-background',
    'red' => 'bg-primary/10 text-primary-ink',
    'brand' => 'bg-primary text-primary-foreground',
    'outline' => 'border border-border text-muted-foreground',
];

function chip(string $text, string $tone = 'neutral', string $iconName = '', string $class = ''): string
{
    $toneClass = CHIP_TONES[$tone] ?? CHIP_TONES['neutral'];
    $html = '<span class="inline-flex items-center gap-1 rounded-[5px] px-2 py-0.5 text-xs leading-5 font-semibold whitespace-nowrap ' . $toneClass . ' ' . h($class) . '">';
    if ($iconName !== '') {
        $html .= icon($iconName, 'size-3.5');
    }
    return $html . h($text) . '</span>';
}

function status_chip(string $status): string
{
    return match ($status) {
        'credited' => chip('Зачислено', 'ink', 'check'),
        'rejected' => chip('Отказ', 'red', 'x'),
        default => chip('Ждёт решения', 'day', 'clock'),
    };
}

function incident_chip(string $status): string
{
    return match ($status) {
        'in_progress' => chip('В работе', 'night', 'wrench'),
        'done' => chip('Закрыта', 'neutral', 'check'),
        default => chip('Новая', 'red', 'alert'),
    };
}

function shift_tone_class(string $type): string
{
    return match ($type) {
        'night' => 'bg-night text-night-foreground',
        'full' => 'shift-full text-foreground',
        default => 'bg-day text-day-foreground',
    };
}

/**
 * Плашка смены для графика. opts: me (id сотрудника текущего пользователя), live (смена идёт прямо сейчас).
 * Разметку повторяет schedule.js для ещё не сохранённых смен «кисти».
 */
function shift_chip(array $shift, array $opts = []): string
{
    $type = (string) ($shift['shiftType'] ?? 'day');
    $away = !empty($shift['awayAtBranchName']);
    $mine = !empty($opts['me']) && (int) ($shift['employeeId'] ?? 0) === (int) $opts['me'];
    $live = !empty($opts['live']);
    $name = (string) ($shift['employeeName'] ?? '');
    $hint = (SHIFT_LABELS[$type] ?? $type) . ' ' . (SHIFT_HINTS[$type] ?? '') . ($away ? ' · в филиале ' . $shift['awayAtBranchName'] : '');
    $cls = 'cal-chip' . ($away ? ' is-away' : '') . ($mine ? ' is-mine' : '') . ($live ? ' is-live' : '');
    $html = '<span class="' . $cls . '" data-tone="' . h($type) . '" title="' . h($name . ' · ' . $hint . ($live ? ' · идёт сейчас' : '')) . '">';
    if ($live) {
        $html .= '<span class="cal-chip-live"><span class="live-dot" aria-hidden="true"></span></span>';
    } else {
        $html .= icon($away ? 'arrow-up-right' : (SHIFT_ICONS[$type] ?? 'sun'), 'cal-chip-icon');
    }
    $html .= '<span class="cal-chip-name"><span class="cal-name-short">' . h(short_name($name)) . '</span><span class="cal-name-full">' . h($name) . '</span></span>';
    if ($away) {
        $html .= '<span class="cal-chip-sub">' . h((string) $shift['awayAtBranchName']) . '</span>';
    }
    if (!empty($shift['guestFromBranchName'])) {
        $html .= '<span class="cal-badge" title="Подмена из филиала ' . h((string) $shift['guestFromBranchName']) . '">' . h(mb_substr((string) $shift['guestFromBranchName'], 0, 3)) . '</span>';
    }
    $sr = ($mine ? ' (вы)' : '') . ', ' . mb_strtolower($hint) . ($live ? ', идёт сейчас' : '');
    return $html . '<span class="sr-only">' . h($sr) . '</span></span>';
}

function empty_state(string $iconName, string $title, string $text = '', string $action = '', string $class = ''): string
{
    $html = '<div class="flex flex-col items-center justify-center gap-3 rounded-lg border border-dashed border-border px-6 py-10 text-center ' . h($class) . '">';
    $html .= '<span class="cut flex size-11 items-center justify-center bg-muted text-muted-foreground" style="--cut:9px">' . icon($iconName, 'size-5') . '</span>';
    $html .= '<div class="flex max-w-sm flex-col gap-1"><p class="text-sm font-semibold">' . h($title) . '</p>';
    if ($text !== '') {
        $html .= '<p class="text-sm leading-relaxed text-muted-foreground text-pretty">' . h($text) . '</p>';
    }
    $html .= '</div>';
    if ($action !== '') {
        $html .= '<div class="mt-1 flex flex-wrap justify-center gap-2">' . $action . '</div>';
    }
    return $html . '</div>';
}

/** Переключатель-ссылки (филиалы, периоды). items: [['href','label','active','count'?]] */
function segmented(array $items, string $label = ''): string
{
    $html = '<nav class="flex max-w-full gap-1 overflow-x-auto rounded-lg border border-border bg-card p-1 shadow-xs scrollbar-none"' . ($label !== '' ? ' aria-label="' . h($label) . '"' : '') . '>';
    foreach ($items as $it) {
        $active = !empty($it['active']);
        $cls = $active
            ? 'bg-foreground text-background'
            : 'text-muted-foreground hover:bg-muted hover:text-foreground';
        $html .= '<a href="' . h($it['href']) . '" class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap transition-colors ' . $cls . '"' . ($active ? ' aria-current="page"' : '') . '>' . h($it['label']);
        if (isset($it['count']) && $it['count'] !== null && $it['count'] !== '') {
            $html .= '<span class="rounded-full px-1.5 text-xs tabular-nums ' . ($active ? 'bg-background/20' : 'bg-muted') . '">' . h((string) $it['count']) . '</span>';
        }
        $html .= '</a>';
    }
    return $html . '</nav>';
}

/** Вкладки-ссылки с подчёркиванием. tabs: [key => ['label','href','count'?]] */
function tabs_nav(array $tabs, string $active, string $label = ''): string
{
    $html = '<nav class="-mx-1 flex gap-1 overflow-x-auto border-b border-border px-1 scrollbar-none"' . ($label !== '' ? ' aria-label="' . h($label) . '"' : '') . '>';
    foreach ($tabs as $key => $t) {
        $on = (string) $key === $active;
        $cls = $on ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground';
        $html .= '<a href="' . h($t['href']) . '" class="-mb-px inline-flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap transition-colors ' . $cls . '"' . ($on ? ' aria-current="page"' : '') . '>' . h($t['label']);
        if (isset($t['count']) && $t['count'] !== null) {
            $html .= '<span class="rounded-full bg-muted px-1.5 text-xs font-semibold text-muted-foreground tabular-nums">' . (int) $t['count'] . '</span>';
        }
        $html .= '</a>';
    }
    return $html . '</nav>';
}

function stat_tile(string $label, string $value, string $hint = '', string $href = '', string $iconName = '', bool $alert = false): string
{
    $tag = $href !== '' ? 'a' : 'div';
    $attrs = $href !== '' ? ' href="' . h($href) . '"' : '';
    $hover = $href !== '' ? ' transition-colors hover:border-foreground/20 hover:bg-muted/40' : '';
    $html = '<' . $tag . $attrs . ' class="surface group flex min-w-0 flex-col gap-2 rounded-lg border border-border bg-card p-4 shadow-xs' . $hover . '">';
    $html .= '<div class="flex items-center justify-between gap-2"><span class="text-xs font-semibold tracking-wide text-muted-foreground uppercase">' . h($label) . '</span>';
    if ($iconName !== '') {
        $html .= '<span class="icon-tile size-7">' . icon($iconName, 'size-3.5') . '</span>';
    }
    $html .= '</div><span class="font-display text-2xl leading-none font-semibold tracking-tight tabular-nums' . ($alert ? ' text-primary-ink' : '') . '">' . $value . '</span>';
    if ($hint !== '') {
        $html .= '<span class="text-xs leading-relaxed text-muted-foreground">' . $hint . '</span>';
    }
    return $html . '</' . $tag . '>';
}

function avatar(string $name, string $class = 'size-9 text-xs', string $tone = 'bg-muted text-muted-foreground'): string
{
    $glyph = '<svg viewBox="0 0 32 32" class="size-full" aria-hidden="true" focusable="false">'
        . '<circle cx="16" cy="12.5" r="5.75" fill="currentColor" opacity=".9"/>'
        . '<path d="M4.5 32c0-6.6 5.15-11.25 11.5-11.25S27.5 25.4 27.5 32Z" fill="currentColor" opacity=".9"/>'
        . '</svg>';
    return '<span class="avatar cut inline-flex shrink-0 items-end justify-center overflow-hidden ring-1 ring-inset ring-border ' . h($tone) . ' ' . h($class) . '" style="--cut:7px" aria-hidden="true" title="' . h($name) . '">' . $glyph . '</span>';
}

function progress_bar(int $pct, string $label = '', string $class = ''): string
{
    $pct = max(0, min(100, $pct));
    return '<div class="h-1.5 w-full overflow-hidden rounded-full bg-muted ' . h($class) . '" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $pct . '"' . ($label !== '' ? ' aria-label="' . h($label) . '"' : '') . '><div class="h-full rounded-full bg-primary transition-[width]" style="width:' . $pct . '%"></div></div>';
}

/** Ячейка таблицы с подписью — на телефоне таблица превращается в карточки. */
function td(string $label, string $inner, string $class = ''): string
{
    return '<td class="px-4 py-3 align-top ' . h($class) . '" data-label="' . h($label) . '">' . $inner . '</td>';
}

function th_cell(string $label, string $class = ''): string
{
    return '<th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold tracking-wide text-muted-foreground uppercase ' . h($class) . '">' . h($label) . '</th>';
}

function table_open(array $headers, string $attrs = '', string $class = ''): string
{
    $html = '<div class="overflow-x-auto rounded-lg border border-border bg-card shadow-xs ' . h($class) . '"><table class="table-cards w-full text-sm"' . ($attrs !== '' ? ' ' . $attrs : '') . '><thead class="bg-muted/50"><tr>';
    foreach ($headers as $hd) {
        if (is_array($hd)) {
            $html .= th_cell($hd[0], $hd[1] ?? '');
        } else {
            $html .= th_cell($hd);
        }
    }
    return $html . '</tr></thead><tbody>';
}

function table_close(): string
{
    return '</tbody></table></div>';
}

function dialog_html(string $id, string $title, string $body, string $class = 'max-w-lg', string $attrs = ''): string
{
    $html = '<dialog id="' . h($id) . '" class="gp-dialog ' . h($class) . '" aria-labelledby="' . h($id) . '-title"' . ($attrs !== '' ? ' ' . $attrs : '') . '>';
    $html .= '<div class="flex items-start justify-between gap-4 border-b border-border px-5 py-4"><h2 id="' . h($id) . '-title" class="font-display text-base leading-snug font-semibold text-pretty" data-dialog-title>' . h($title) . '</h2>';
    $html .= '<button type="button" class="' . btn_class('quiet', 'icon-sm') . ' -mt-1 -mr-2" data-dialog-close aria-label="Закрыть">' . icon('x', 'size-4') . '</button></div>';
    $html .= '<div class="flex flex-col gap-4 px-5 py-5">' . $body . '</div></dialog>';
    return $html;
}

function back_link(string $href, string $label): string
{
    return '<a href="' . h($href) . '" class="inline-flex w-fit items-center gap-1.5 text-sm font-medium text-muted-foreground transition-colors hover:text-foreground">' . icon('arrow-left', 'size-4') . h($label) . '</a>';
}

function search_input(string $attrs, string $placeholder = 'Поиск', string $class = ''): string
{
    return '<div class="relative ' . h($class) . '">' . icon('search', 'pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground')
        . '<input type="search" class="' . h(field_class('pl-9')) . '" placeholder="' . h($placeholder) . '" aria-label="' . h($placeholder) . '" ' . $attrs . '></div>';
}
