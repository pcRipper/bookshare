/**
 * Mailpit's REST API, wrapped.
 *
 * Asserting against the catcher rather than against the app is the whole point:
 * it is the only vantage point from which "queued" and "delivered" are different
 * facts. Everything upstream of the SMTP handshake is already covered by
 * PHPUnit.
 */
const MAILPIT = process.env.E2E_MAILPIT_URL ?? 'http://localhost:8025'

const api = (path) => `${MAILPIT}/api/v1${path}`

/**
 * Empties the inbox. Every spec starts from zero — a mail matched by subject
 * alone would otherwise be satisfied by a previous spec's leftovers, which is
 * exactly how an "assert no mail was sent" test passes for the wrong reason.
 */
export async function purgeInbox(request) {
  const response = await request.delete(api('/messages'))
  if (!response.ok()) {
    throw new Error(`Mailpit purge failed (${response.status()}). Is the mailpit container up?`)
  }
}

/** @returns {Promise<Array<object>>} the inbox, newest first. */
export async function listMessages(request) {
  const response = await request.get(api('/messages?limit=100'))
  if (!response.ok()) {
    throw new Error(`Mailpit is not reachable at ${MAILPIT} (${response.status()}).`)
  }

  return (await response.json()).messages
}

/**
 * Polls until one message matches, or fails with the inbox contents — a bare
 * timeout here tells you nothing, while "these three arrived, none matched" is
 * usually the whole diagnosis.
 */
export async function waitForMessage(request, predicate, { timeout = 20_000, label = 'a matching mail' } = {}) {
  const deadline = Date.now() + timeout
  let seen = []

  while (Date.now() < deadline) {
    seen = await listMessages(request)
    const match = seen.find(predicate)
    if (match) {
      return match
    }
    await new Promise((resolve) => setTimeout(resolve, 400))
  }

  const summary = seen.map((m) => `  - ${m.Subject} → ${m.To.map((t) => t.Address).join(', ')}`).join('\n')

  throw new Error(`Timed out waiting for ${label}. Inbox held ${seen.length} message(s):\n${summary || '  (empty)'}`)
}

/**
 * Asserts nothing arrives. Waits out a fixed window rather than checking once:
 * the mail would be a moment late, not absent, so a single check would pass
 * whether the opt-out worked or not.
 */
export async function expectNoMessage(request, predicate, { window = 6_000 } = {}) {
  const deadline = Date.now() + window

  while (Date.now() < deadline) {
    const match = (await listMessages(request)).find(predicate)
    if (match) {
      throw new Error(`Expected no mail, but received: "${match.Subject}" → ${match.To.map((t) => t.Address).join(', ')}`)
    }
    await new Promise((resolve) => setTimeout(resolve, 400))
  }
}

/** The full message: both body parts, headers, addresses. */
export async function readMessage(request, id) {
  const response = await request.get(api(`/message/${id}`))
  if (!response.ok()) {
    throw new Error(`Could not read Mailpit message ${id} (${response.status()}).`)
  }

  return response.json()
}

/** Convenience matchers, so specs read as intent rather than as JSON paths. */
export const to = (address) => (message) => message.To.some((t) => t.Address === address)
export const subjectContains = (text) => (message) => message.Subject.includes(text)
export const and =
  (...predicates) =>
  (message) =>
    predicates.every((predicate) => predicate(message))
