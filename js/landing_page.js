/**
 * @file
 * Landing page behaviors.
 */

(function (Drupal, drupalSettings, once) {
  Drupal.behaviors.makerspaceLandingPage = {
    attach: function (context, settings) {
      // Ensure we have settings
      if (!settings.makerspace_landing_page) {
        return;
      }

      const { coupon, tracking_code } = settings.makerspace_landing_page;

      // Apply Coupon
      if (coupon) {
        // Process Chargebee links
        const chargebeeLinks = once('makerspace-landing-coupon-links', 'a[href*="makehaven.chargebee.com"]', context);
        if (chargebeeLinks.length > 0) {
          console.log(`Makerspace Landing Page: Found ${chargebeeLinks.length} new Chargebee links.`);
          chargebeeLinks.forEach(anchor => {
             try {
              const url = new URL(anchor.href, window.location.origin);
              let updated = false;
              if (!url.searchParams.has('subscription[coupon]')) {
                url.searchParams.set('subscription[coupon]', coupon);
                updated = true;
              }
              if (!url.searchParams.has('coupon')) {
                url.searchParams.set('coupon', coupon);
                updated = true;
              }
              if (updated) {
                anchor.href = url.toString();
                console.log('Makerspace Landing Page: Updated link', anchor.href);
              }
            } catch (e) {
              console.error('Makerspace Landing Page: Error updating link', e);
            }
          });
        }

        // Process Chargebee elements
        const chargebeeElements = once('makerspace-landing-coupon-elements', '[data-cb-type], [data-cb-item-0-id], [data-cb-plan-id], [data-cb-item-0], [data-cb-item]', context);
        chargebeeElements.forEach(element => {
          if (!element.hasAttribute('data-cb-coupon') || !element.getAttribute('data-cb-coupon')) {
            element.setAttribute('data-cb-coupon', coupon);
          }
        });
      }

      // Apply Tracking Code
      if (tracking_code) {
        // 1. Update links that already have utm_campaign
        once('makerspace-landing-tracking-existing', 'a[href*="utm_campaign="]', context).forEach(anchor => {
            updateUrl(anchor, tracking_code);
        });

        // 2. Add to every conversion target that is still untagged.
        once('makerspace-landing-tracking-targets', 'a[href]:not([href*="utm_campaign="])', context).forEach(anchor => {
            if (isConversionTarget(anchor)) {
              updateUrl(anchor, tracking_code);
            }
        });
      }
    }
  };

  /**
   * Paths where a visitor converts, and which therefore need the campaign tag.
   *
   * This used to be a CSS attribute selector for `/tour` plus Chargebee, which
   * silently missed most of the funnel: `/tour` is only a redirect, so a link
   * written as `/open-tours` matched nothing, and the join links were never
   * tagged at all — so "who joined from this campaign?" had no answer anywhere.
   * Matched on the parsed pathname so a query string or anchor cannot hide them.
   */
  const CONVERSION_PATHS = [
    '/tour',
    '/open-tours',
    '/join-makehaven',
    '/take-next-step',
    '/user/register',
  ];

  const CONVERSION_HOSTS = ['makehaven.chargebee.com'];

  function isConversionTarget(anchor) {
    try {
      const url = new URL(anchor.href, window.location.origin);
      if (CONVERSION_HOSTS.indexOf(url.hostname) !== -1) {
        return true;
      }
      // Trailing-slash tolerant exact-or-prefix match, so `/tour` catches
      // `/tour/` but never `/tournament`.
      const path = url.pathname.replace(/\/+$/, '');
      return CONVERSION_PATHS.some(p => path === p || path.endsWith(p));
    } catch (e) {
      return false;
    }
  }

  function updateUrl(anchor, campaign) {
      try {
        const url = new URL(anchor.href, window.location.origin);
        
        if (!url.searchParams.has('utm_source')) {
          url.searchParams.set('utm_source', 'landing_page');
        }
        if (!url.searchParams.has('utm_medium')) {
          url.searchParams.set('utm_medium', 'website');
        }
        
        url.searchParams.set('utm_campaign', campaign);
        
        anchor.href = url.toString();
      } catch (e) {
        // Ignore
      }
  }

})(Drupal, drupalSettings, once);