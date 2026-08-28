# Server Stability Observations (local note, informs future deployment/hardening work)

From a real incident on the domain owner's current production WordPress
site (server went down):

1. WordPress Heartbeat API — wp-admin's admin-ajax.php receives frequent
   periodic polling requests from every open admin browser tab (a
   WordPress-specific keep-alive/live-update mechanism). This load
   compounds with multiple open admin tabs/sessions. EasyCo, being a
   Laravel application with no equivalent always-on admin polling
   mechanism by default, does not have this specific problem class -
   noted so nobody accidentally reintroduces something similar in the
   future admin UI (Livewire/Filament) without deliberately deciding to.

2. Bot scanning traffic - a universal problem for any public site,
   not WordPress-specific. EasyCo has no special immunity to this.
   This belongs in a future production-hardening/deployment pass:
   rate limiting and bot detection at the web-server/infrastructure
   layer (e.g. Nginx/Cloudflare-level), not something to solve inside
   the Laravel application itself.

This incident is part of the real motivation for building EasyCo in the
first place - not just a tangential complaint. Not designed yet - no
action taken, this is a placeholder so the observation isn't lost
before a deployment/hardening pass is scheduled.
