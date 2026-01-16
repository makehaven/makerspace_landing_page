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

        // 2. Add to specific targets if missing
        const specificTargetsSelector = 'a[href*="/tour"]:not([href*="utm_campaign="]), a[href*="makehaven.chargebee.com"]:not([href*="utm_campaign="])';
        once('makerspace-landing-tracking-targets', specificTargetsSelector, context).forEach(anchor => {
            updateUrl(anchor, tracking_code);
        });
      }
    }
  };

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