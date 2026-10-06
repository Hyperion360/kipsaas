<?php // src/Signup.php
declare(strict_types=1);

namespace KipSaaS;

/**
 * Onboarding: submit (create pending + mail verify link) and claim (prove
 * email ownership, flip to verified). Billing starts only after claim, and
 * activation only after a verified Stripe webhook, never from a client
 * redirect. Mail is injected as a closure so tests capture it.
 */
final class Signup
{
    /** @param \Closure(string,string,string):void $mail (to, subject, body) */
    public function __construct(private Tenants $tenants, private array $config, private \Closure $mail) {}

    /** @return array{status: string, slug?: string, error?: string} */
    public function submit(string $email, string $rawTitle, string $plan, string $ip): array
    {
        $email = strtolower(trim($email));
        // Two buckets: the client IP (a trusted proxy's real-client header,
        // falling back to REMOTE_ADDR) and the email itself, so a spoofed
        // header cannot burn another IP's bucket and one shared IP cannot
        // lock everyone out of signup.
        if (!RateLimit::hit($this->registryPdo(), 'signup:' . $ip, 10, 3600)
            || !RateLimit::hit($this->registryPdo(), 'signupemail:' . $email, 5, 3600)) {
            return ['status' => 'rate_limited', 'error' => 'Too many signups; try again later.'];
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['status' => 'error', 'error' => 'Enter a valid email address.'];
        }
        try {
            $slug = Slug::normalize($rawTitle);
        } catch (\DomainException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
        // A plan with no price id is a manual-only tier: the form never
        // offers it, so a value naming one is as wrong as an unknown key.
        if (!isset($this->config['plans'][$plan]['price_id']) || $this->config['plans'][$plan]['price_id'] === '') {
            return ['status' => 'error', 'error' => 'Pick a plan.'];
        }
        if ($this->tenants->bySlug($slug) !== null) {
            return ['status' => 'error', 'error' => 'That name is taken.'];
        }
        $title = mb_substr(trim(strip_tags($rawTitle)), 0, 60);
        if ($title === '') $title = $slug;
        $token = Tokens::issue($this->config['token_secret']);
        $host = Slug::hostFor($slug, $this->config['base_domain']);
        $this->tenants->create($slug, $host, $plan, $email, $title, $token['hash'], $token['expires_at']);
        $link = $this->config['control_base_url'] . '/verify?t=' . urlencode($token['token']);
        ($this->mail)($email, 'Confirm your signup: ' . $title,
            "Confirm your email to create {$title} at {$host}:\n\n{$link}\n\nThe link expires in 24 hours.\n");
        return ['status' => 'pending', 'slug' => $slug];
    }

    /** @return array{status: 'verified'|'already_claimed'|'error', tenant?: array, error?: string} */
    public function claim(string $givenToken): array
    {
        $hash = hash('sha256', $givenToken);
        $t = $this->tenants->byTokenHash($hash);
        if ($t !== null && $t['status'] !== 'pending') {
            // This link's token matches a row that already claimed: the first
            // submit won. The caller answers with a clean redirect, never a
            // scary 403 and never a second checkout session.
            return ['status' => 'already_claimed'];
        }
        if ($t === null
            || !Tokens::valid($givenToken, $this->config['token_secret'], (string) $t['verify_token_hash'], (string) $t['verify_expires_at'])) {
            return ['status' => 'error', 'error' => 'That link is invalid, expired, or already used.'];
        }
        // The race gate: one compare-and-swap flips pending -> verified, so of
        // two concurrent submits of the same link exactly one wins and opens
        // the checkout; the loser joins the already-claimed path above.
        if (!$this->tenants->markVerified((int) $t['id'], $hash)) {
            return ['status' => 'already_claimed'];
        }
        return ['status' => 'verified', 'tenant' => $this->tenants->byId((int) $t['id'])];
    }

    private function registryPdo(): \PDO
    {
        // Signup reaches the limiter through the repository's connection; the
        // controller constructs Signup with both sharing one Registry.
        return $this->tenants->pdo();
    }
}
