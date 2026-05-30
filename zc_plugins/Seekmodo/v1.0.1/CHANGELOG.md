# Seekmodo Zen Cart connector — changelog

## v1.0.1
- Connector now pages through gateway results (per_page=250 x 50 pages) so Zen Cart's local pagination sees every matching product, not just the first 10. Fixes 'more won't load' on storefronts where shoppers expected continuous scroll past page 1.
- Force IPv4 + relax connect timeout to 250-750ms (was 200ms) in Client.php and RemoteConfig.php — flaky CF IPv6 path on Redline web03 was tripping the breaker spuriously.
- Response normalizer handles the gateway's nested results.hits[*].document envelope in addition to the legacy flat {products,total} shape.
