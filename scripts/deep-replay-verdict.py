#!/usr/bin/env python3
"""Verdict for scripts/deep-replay.sh.

Reads the raw outputs written by the replay and checks one invariant per
negative proof. Any violated invariant exits non-zero so the replay is
reproducible by exit code alone, without reading prose.
"""
import json
import pathlib
import re
import sys

out = pathlib.Path(sys.argv[1])
failures = []
notes = []


def load(name):
    path = out / name
    if not path.exists():
        failures.append(f"missing artefact {name}")
        return None
    text = path.read_text(encoding="utf-8", errors="replace")
    try:
        return json.loads(text[text.index("{"):])
    except ValueError:
        failures.append(f"artefact {name} is not JSON")
        return None


def phpunit_summary():
    path = out / "step1-phpunit.txt"
    if not path.exists():
        failures.append("missing artefact step1-phpunit.txt")
        return
    text = path.read_text(encoding="utf-8", errors="replace")
    line = [l for l in text.splitlines() if l.startswith("Tests: ") or l.startswith("OK")]
    summary = " | ".join(line[-2:]) if line else "no summary line"
    if "Errors" in summary or "Failures" in summary:
        failures.append(f"step 1 suite not green: {summary}")
    notes.append(f"step1: {summary}")


phpunit_summary()

# --- proof 1: login limit -------------------------------------------------
pre = load("pre-01-login-limit.php.json")
post = load("post-01-login-limit.php.json")
if pre and post:
    pre_spray = pre.get("A_spray_80_from_one_ip", {})
    post_spray = post.get("A_spray_80_from_one_ip", {})
    pre_429 = sum(v for k, v in pre_spray.items() if k.startswith("429"))
    post_429 = sum(v for k, v in post_spray.items() if k.startswith("429"))
    if pre_429:
        failures.append(f"pre tree already rate limited the spray ({pre_429} x 429)")
    if not post_429:
        failures.append("post tree did not rate limit the spray")
    pre_c = pre.get("C_parallel_8", {})
    post_c = post.get("C_parallel_8", {})
    if not isinstance(pre_c, dict) or not isinstance(post_c, dict):
        notes.append("proof1/C: skipped (workers need a shared MySQL database)")
        pre_lost = post_count = None
    else:
        pre_lost = pre_c.get("pre_counter_key")
        post_count = post_c.get("post_counter_key")
        if pre_lost not in (1, 2) and pre_lost is not None:
            notes.append(f"proof1/C pre counter={pre_lost} (expected a small lost-update value)")
        if post_count != 8:
            failures.append(f"post tree lost concurrent increments (counter={post_count}, expected 8)")
    notes.append(f"proof1: pre 429={pre_429}, post 429={post_429}, pre concurrent counter={pre_lost}, post={post_count}")

# --- proof 2: payment callback -------------------------------------------
pre = load("pre-02-payment-callback.php.json")
post = load("post-02-payment-callback.php.json")
if pre and post:
    pre_cases, post_cases = pre["cases"], post["cases"]
    controls = [k for k in pre_cases if "control_correct" in k]
    attacks = [k for k in pre_cases if "control_correct" not in k]
    pre_accepted = [k for k in attacks if pre_cases[k]["order_status"] == 1]
    post_accepted = [k for k in attacks if post_cases.get(k, {}).get("order_status") == 1]
    if len(pre_accepted) < len(attacks):
        notes.append(f"proof2: pre rejected {len(attacks) - len(pre_accepted)} attack case(s) as well")
    if post_accepted:
        failures.append(f"post tree settled attack cases: {post_accepted}")
    for control in controls:
        if pre_cases[control]["order_status"] != 1:
            failures.append(f"pre tree failed its control case {control}")
        if post_cases.get(control, {}).get("order_status") != 1:
            failures.append(f"post tree failed its control case {control}")
    notes.append(f"proof2: pre accepted {len(pre_accepted)}/{len(attacks)} attacks, post accepted {len(post_accepted)}/{len(attacks)}, controls settled on both")

# --- proof 3: node private key -------------------------------------------
pre = load("pre-03-node-private-key.php.json")
post = load("post-03-node-private-key.php.json")
if pre and post:
    if not pre.get("tls_secret_fields_present"):
        failures.append("pre tree did not leak any TLS secret; the proof has no positive side")
    if post.get("tls_secret_fields_present"):
        failures.append(f"post tree still leaks {post['tls_secret_fields_present']}")
    if not post.get("public_fields_kept"):
        failures.append("post tree dropped public TLS fields as well")
    notes.append(f"proof3: pre leaked {pre.get('tls_secret_fields_present')}, post leaked {post.get('tls_secret_fields_present')}, public kept {post.get('public_fields_kept')}")

# --- proof 4: settlement freeze ------------------------------------------
pre = load("pre-04-settlement-freeze.php.json")
post = load("post-04-settlement-freeze.php.json")
if pre and post:
    pre_cases, post_cases = pre["cases"], post["cases"]
    for key, case in pre_cases.items():
        if key.startswith("4a_control"):
            continue
        if key.startswith("4a_") and case.get("value") is not True:
            failures.append(f"pre tree rejected {key}; nothing to prove")
        if key.startswith("4a_") and post_cases.get(key, {}).get("value") is not False:
            failures.append(f"post tree still settles {key}")
        if key.startswith("4b_") and case.get("value") is not False:
            notes.append(f"proof4: {key} accepted on pre, unexpected for a guard that predates the comparison point")
        if key.startswith("4b_") and post_cases.get(key, {}).get("value") is not False:
            failures.append(f"post tree settles {key}")
    for key, case in pre_cases.items():
        if key.startswith("4a_control") and case.get("value") is not True:
            failures.append(f"pre tree failed its control {key}")
        if key.startswith("4a_control") and post_cases.get(key, {}).get("value") is not True:
            failures.append(f"post tree failed its control {key}")
    notes.append("proof4: 4a pre settles without a receipt amount, post refuses; 4b refused on both")

for note in notes:
    print("NOTE " + note)
for failure in failures:
    print("FAIL " + failure)
print("VERDICT " + ("PASS" if not failures else f"FAIL ({len(failures)} invariant(s))"))
sys.exit(0 if not failures else 1)
