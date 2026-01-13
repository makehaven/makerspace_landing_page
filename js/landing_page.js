/**
 * @file
 * Landing page behaviors.
 */

(function (Drupal, drupalSettings) {
  Drupal.behaviors.makerspaceLandingPage = {
    attach: function (context, settings) {
      console.log('Makerspace Landing Page: Library attached.');
      // Ensure we have settings
      if (!settings.makerspace_landing_page) {
        console.warn('Makerspace Landing Page: No settings found.');
        return;
      }

      const { coupon, tracking_code } = settings.makerspace_landing_page;
      console.log('Makerspace Landing Page: Settings loaded', { coupon, tracking_code });

      // Apply Coupon
      if (coupon) {
        const chargebeeLinks = context.querySelectorAll('a[href*="makehaven.chargebee.com"]');
        console.log(`Makerspace Landing Page: Found ${chargebeeLinks.length} Chargebee links.`);
        
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

        const chargebeeElements = context.querySelectorAll('[data-cb-type], [data-cb-item-0-id], [data-cb-plan-id], [data-cb-item-0], [data-cb-item]');
        chargebeeElements.forEach(element => {
          if (!element.hasAttribute('data-cb-coupon') || !element.getAttribute('data-cb-coupon')) {
            element.setAttribute('data-cb-coupon', coupon);
          }
        });
      }

      // Apply Tracking Code
      if (tracking_code) {
        // 1. Update links that already have utm_campaign
        const linksWithCampaign = context.querySelectorAll('a[href*="utm_campaign="]');
        linksWithCampaign.forEach(anchor => {
            updateUrl(anchor, tracking_code);
        });

        // 2. Add to specific targets if missing
        const specificTargets = context.querySelectorAll(
            'a[href*="/tour"]:not([href*="utm_campaign="]), a[href*="makehaven.chargebee.com"]:not([href*="utm_campaign="])'
        );
        specificTargets.forEach(anchor => {
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

})(Drupal, drupalSettings);
