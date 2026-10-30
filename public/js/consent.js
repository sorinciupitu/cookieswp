/* Consent Suite — frontend engine */
( function () {
    'use strict';

    var CFG    = window.CCM_CONFIG || {};
    var CATS   = [ 'functional', 'analytics', 'advertisement' ];
    var COOKIE = 'ccm_consent';
    var state  = { consent: null, banner: null, modal: null, revisit: null };

    /* ---------- helpers ---------- */
    function readCookie( name ) {
        var m = document.cookie.match( new RegExp( '(?:^|; )' + name + '=([^;]*)' ) );
        return m ? decodeURIComponent( m[ 1 ] ) : null;
    }
    function writeCookie( name, value, days ) {
        var d = new Date( Date.now() + days * 864e5 );
        document.cookie = name + '=' + encodeURIComponent( value ) +
            '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax' +
            ( 'https:' === location.protocol ? '; Secure' : '' );
    }
    function uuid() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
            var r = Math.random() * 16 | 0;
            return ( 'x' === c ? r : ( r & 3 | 8 ) ).toString( 16 );
        } );
    }
    function el( tag, cls ) {
        var e = document.createElement( tag );
        if ( cls ) e.className = cls;
        return e;
    }
    function button( label, cls, onClick ) {
        var b = el( 'button', cls ); b.type = 'button';
        b.textContent = label || '';
        b.addEventListener( 'click', onClick );
        return b;
    }
    function setVars( c ) {
        var s = document.documentElement.style;
        if ( ! c ) return;
        if ( c.bg )       s.setProperty( '--ccm-bg', c.bg );
        if ( c.text )     s.setProperty( '--ccm-text', c.text );
        if ( c.btn_bg )   s.setProperty( '--ccm-primary', c.btn_bg );
        if ( c.btn_text ) s.setProperty( '--ccm-btn-text', c.btn_text );
    }

    /* ---------- consent core ---------- */
    function read() {
        try { return JSON.parse( readCookie( COOKIE ) || 'null' ); } catch ( e ) { return null; }
    }
    function fresh( all ) {
        var c = { v: CFG.consentVersion || 1, t: Date.now(),
            id: ( state.consent && state.consent.id ) || uuid(), necessary: true };
        CATS.forEach( function ( k ) {
            c[ k ] = true === all ? true : ( false === all ? false : !!all[ k ] );
        } );
        return c;
    }
    function isValid( c ) { return !!c && c.v === ( CFG.consentVersion || 1 ); }

    function save( consent, action ) {
        state.consent = consent;
        writeCookie( COOKIE, JSON.stringify( consent ), CFG.expiry || 365 );
        activate();
        gcm( consent );
        log( action, consent );
        syncWpConsentApi( consent );
        document.dispatchEvent( new CustomEvent( 'ccm:changed', { detail: { consent: consent, action: action } } ) );
        if ( state.banner ) { state.banner.remove(); state.banner = null; }
        addRevisit();
    }

    /** Re-execute neutralized scripts / restore iframes the visitor consented to */
    function activate( scope ) {
        var root = scope || document;
        root.querySelectorAll( 'script[type="text/plain"][data-ccm-category]' ).forEach( function ( s ) {
            var cat = s.getAttribute( 'data-ccm-category' );
            if ( 'necessary' !== cat && ! ( state.consent && state.consent[ cat ] ) ) return;
            var n = document.createElement( 'script' );
            [].forEach.call( s.attributes, function ( a ) {
                if ( 'type' !== a.name && 'data-ccm-category' !== a.name ) n.setAttribute( a.name, a.value );
            } );
            n.textContent = s.textContent;
            s.parentNode.replaceChild( n, s );
        } );
        root.querySelectorAll( 'iframe[data-ccm-src]' ).forEach( function ( f ) {
            if ( ! ( state.consent && state.consent[ f.getAttribute( 'data-ccm-category' ) ] ) ) return;
            f.src = f.getAttribute( 'data-ccm-src' );
            f.removeAttribute( 'data-ccm-src' );
        } );
    }

    /** Google Consent Mode v2 */
    function gcm( c ) {
        if ( ! CFG.gcm ) return;
        window.dataLayer = window.dataLayer || [];
        var g = window.gtag || function () { window.dataLayer.push( arguments ); };
        g( 'consent', 'update', {
            ad_storage:            c.advertisement ? 'granted' : 'denied',
            ad_user_data:          c.advertisement ? 'granted' : 'denied',
            ad_personalization:    c.advertisement ? 'granted' : 'denied',
            analytics_storage:     c.analytics     ? 'granted' : 'denied',
            functionality_storage: c.functional    ? 'granted' : 'denied',
            personalization_storage: c.functional  ? 'granted' : 'denied',
            security_storage:      'granted'
        } );
    }

    function log( action, c ) {
        if ( ! CFG.log || ! CFG.restUrl || 'gpc' === action ) return;
        try {
            fetch( CFG.restUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify( {
                    action: action, uuid: c.id, url: location.href,
                    functional: !!c.functional, analytics: !!c.analytics, advertisement: !!c.advertisement
                } ),
                keepalive: true
            } ).catch( function () {} );
        } catch ( e ) {}
    }

    /** WP Consent API compatibility (rlankhorst/wp-consent-api) */
    function syncWpConsentApi( c ) {
        if ( 'function' !== typeof window.wp_set_consent ) return;
        window.wp_consent_type = 'optin';
        window.wp_set_consent( 'functional', c.functional    ? 'allow' : 'deny' );
        window.wp_set_consent( 'analytics',  c.analytics     ? 'allow' : 'deny' );
        window.wp_set_consent( 'marketing',  c.advertisement ? 'allow' : 'deny' );
    }

    /* ---------- UI ---------- */
    function buildBanner() {
        var t = CFG.texts || {};
        var b = el( 'div', 'ccm-banner ccm-pos-' + ( CFG.position || 'bottom-left' ) + ( 'bar' === CFG.layout ? ' ccm-bar' : '' ) );
        if ( t.title ) { var h = el( 'h2', 'ccm-title' ); h.textContent = t.title; b.appendChild( h ); }
        var p = el( 'p', 'ccm-text' ); p.textContent = t.message || ''; b.appendChild( p );

        var row = el( 'div', 'ccm-actions' );
        var accept = button( t.accept || 'Accept All', 'ccm-btn ccm-btn--primary',
    function () { save( fresh( true ), 'accept_all' ); } );
accept.id = 'ccm-accept';
row.appendChild( accept );

if ( CFG.showReject ) {
    var reject = button( t.reject || 'Reject All', 'ccm-btn ccm-btn--ghost',
        function () { save( fresh( false ), 'reject_all' ); } );
    reject.id = 'ccm-reject';
    row.appendChild( reject );
}

var prefsBtn = button( t.prefs || 'Customize', 'ccm-btn ccm-btn--ghost', openPrefs );
prefsBtn.id = 'ccm-prefs';
row.appendChild( prefsBtn );

b.appendChild( row );
document.body.appendChild( b );
    }

    function openPrefs() {
        if ( state.modal ) return;
        var t    = CFG.texts || {};
        var wrap = el( 'div', 'ccm-modal' );
        var card = el( 'div', 'ccm-modal__card' );
        var h    = el( 'h2', 'ccm-title' ); h.textContent = t.prefs_title || 'Privacy preferences';
        card.appendChild( h );

        var list  = el( 'div', 'ccm-cats' );
        var boxes = {};
        Object.keys( CFG.categories || {} ).forEach( function ( key ) {
            var cat = CFG.categories[ key ];
            var row = el( 'label', 'ccm-cat' );
            var cb  = el( 'input' ); cb.type = 'checkbox';
            cb.checked = cat.locked ? true :
                ( state.consent ? !!state.consent[ key ] : !!cat.default_on );
            if ( cat.locked ) cb.disabled = true;
            var span = el( 'span' ); span.textContent = cat.label || key;
            row.appendChild( cb ); row.appendChild( span );
            list.appendChild( row );
            boxes[ key ] = cb;
        } );
        card.appendChild( list );
        card.appendChild( button( t.save || 'Save My Preferences', 'ccm-btn ccm-btn--primary', function () {
            var c = fresh( false );
            CATS.forEach( function ( k ) { c[ k ] = boxes[ k ] ? boxes[ k ].checked : false; } );
            save( c, 'custom' );
            wrap.remove(); state.modal = null;
        } ) );

        var x = el( 'button', 'ccm-modal__close' ); x.type = 'button'; x.textContent = '×';
        x.addEventListener( 'click', function () { wrap.remove(); state.modal = null; } );
        card.appendChild( x );
        wrap.appendChild( card );
        wrap.addEventListener( 'click', function ( e ) {
            if ( e.target === wrap ) { wrap.remove(); state.modal = null; }
        } );
        document.body.appendChild( wrap );
        state.modal = wrap;
    }

    function addRevisit() {
        if ( state.revisit ) return;
        var r = el( 'button', 'ccm-revisit' );
        r.type = 'button';
        r.textContent = ( CFG.texts && CFG.texts.revisit ) || 'Cookie Settings';
        r.addEventListener( 'click', openPrefs );
        document.body.appendChild( r );
        state.revisit = r;
    }

    /* ---------- init ---------- */
    function init() {
        setVars( CFG.colors );
        state.consent = read();

        // Public API (mirrors CookieYes' window.CookieYes API style)
        window.CCM = {
            showPreferences: openPrefs,
            acceptAll:  function () { save( fresh( true ),  'accept_all' ); },
            rejectAll:  function () { save( fresh( false ), 'reject_all' ); },
            getConsent: function () { return state.consent; },
            revoke: function () {
                document.cookie = COOKIE + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
                location.reload();
            }
        };

        // CCPA: Global Privacy Control → auto deny-all, no banner
        if ( CFG.gpc && true === navigator.globalPrivacyControl ) {
            save( fresh( false ), 'gpc' );
            return;
        }

        activate(); // necessary scripts + anything allowed by stored consent
        gcm( isValid( state.consent ) ? state.consent : fresh( false ) );

        if ( ! isValid( state.consent ) ) buildBanner();
        else addRevisit();
    }

    if ( 'loading' === document.readyState ) document.addEventListener( 'DOMContentLoaded', init );
    else init();
} )();
