*[Versione italiana](README.md)*

# Osply

A digital guest guide for hospitality businesses.

Osply lets a host gather everything a guest needs during their stay on a single page: check-in,
WiFi, house rules, emergencies, check-out and local recommendations.

The guide is shared through a link, a QR code or WhatsApp, and is designed primarily for use on
a smartphone.

🔗 **Live:** [osply.app](https://osply.app)

🔗 **Demo guide:** [osply.app/g/casa-azzurra-palermo](https://osply.app/g/casa-azzurra-palermo)

---

> **What this repository contains.** It is not the Osply source code, but a technical
> description of how I designed and built it, with a few code excerpts in support. The full
> codebase is private. If you are evaluating my profile and would like to see it, I am happy to
> walk through it on a screen share or to give you read access to the private repository.

---

## Overview

**Laravel 13 · PHP 8.3 · Livewire 4 · Tailwind 4 · MySQL**

I handled design, development, testing and deployment directly.

| | |
|---|---|
| Code commits | 117 |
| Tests | 250 (Pest) — 246 passing, 4 skipped |
| Application PHP classes | 36 |
| Blade views | 77 |
| Period | June – September 2026, two milestones |
| Status | running in production on a VPS, own domain, HTTPS |

---

## The guest guide

| Guide sections | Protected content | Emergencies and host contact |
|---|---|---|
| ![Guest guide](docs/img/01-guest-full.png) | ![Protected content](docs/img/02-guest-full.png) | ![Bottom of the guide](docs/img/03-guest-full.png) |

---

## Technical documentation

* [Architecture](docs/architettura.md) — stack, data model, routes, authorization, deployment
* [Technical decisions](docs/decisioni-tecniche.md) — choices, alternatives considered, one mistake
* [How a CRUD is written here](docs/crud-pattern.md) — everyday code, with its tests
* [Code excerpts](examples/) — seven source files, including one feature from migration to tests

*(the three documents are in Italian)*

---

## Some technical choices

### 1. Sitemap cache: a problem that surfaced in production

`/sitemap.xml` worked correctly on the first request, while every subsequent one returned a 500.

The cause was a difference between the test and production environments: tests used the `array`
cache driver, production used `database`.

The controller was caching an Eloquent Collection directly. With the database driver the value
is serialized, and on the next read it no longer behaved as expected.

The fix was to cache only the data needed, as a plain array:

```php
$properties = Cache::remember('sitemap_v2', 3600, fn () =>
    Property::where('vetrina_enabled', true)
        ->select(['slug', 'updated_at'])
        ->get()
        ->map(fn (Property $property) => [
            'slug'       => $property->slug,
            'updated_at' => $property->updated_at->toAtomString(),
        ])
        ->all()
);
```

After the fix I also added a dedicated test using the `database` driver, so that the production
behaviour is reproduced in the suite.

The cache key is versioned because the production environment does not expose the PHP CLI over
SSH. A change to the shape of the data can therefore move to a new key immediately, without
requiring a `cache:clear`.

### 2. Privacy and data handling

Guests do not create an account and do not log in.

The data collected is limited to what the guide and its statistics actually need. The IP address
is not stored directly but hashed.

For enquiries coming from the public listing page, the moment consent was given is also
recorded.

A retention command removes closed enquiries automatically once the defined period has passed.

### 3. Protecting content with a PIN

Some information, such as WiFi credentials or entry codes, can be protected with a PIN.

The PIN is stored as a hash, and the unlocked state is kept in the guest's session.

Rate limiting uses a key based on both IP and property, with a maximum of five attempts in ten
minutes. Attempts made against one property therefore do not interfere with access to the
others.

Host access to their own properties is handled through Laravel Policies. Every method that
writes to the database follows the same shape:

```php
$this->authorize('update', $this->property);
$recommendation = $this->property->localRecommendations()->findOrFail($id);
```

Authorization first, then loading through the already-scoped relation. An identifier belonging
to another property is not found, with no need for additional checks. The details, with
the relevant tests, are in [How a CRUD is written here](docs/crud-pattern.md).

### 4. Normalising WhatsApp numbers

To build `wa.me` links correctly I isolated phone number normalisation in a small value object:

```php
// Returns the digits in E.164 form, or null when the number is ambiguous.
public static function toE164(?string $raw): ?string
```

It handles the most common formats and, when a number cannot be normalised with confidence, it
avoids building a potentially wrong link and falls back to a safer alternative.

The class is independent from the rest of the application and covered by unit tests.

---

## How it is tested

The suite has 250 Pest tests, mostly feature tests against application behaviour.

Covered, among others:

* authorization and access to properties;
* public and protected content;
* PIN and rate limiting;
* enquiry privacy;
* WhatsApp links and QR codes;
* regressions found while running in production.

Pint and Larastan also run automatically through GitHub Actions.

---

## Product choices

The first version was deliberately limited to what was needed to create and share a guest guide.

PMS, booking engine, payments, multi-language and chatbot were all left out of the first
milestone.

v1.1 later added the public listing page and enquiry handling, keeping the two development
phases separate.

---

## What I would improve

The sitemap problem showed how much it matters to reduce the differences between the test and
production environments. I would add at least a few smoke tests to CI, running against the same
drivers used in production.

I would also improve automatic image format handling for the mobile page, and finish scheduling
the retention command.

---

## License

The material in this repository is published for the purpose of technical evaluation.

It may be read and quoted, but not reused, redistributed or deployed.

See [LICENSE](LICENSE).
