import { createHmac, timingSafeEqual } from 'node:crypto';

/**
 * Verify NGO.Tools webhook signature (HMAC-SHA256).
 * Formula: HMAC-SHA256(secret, timestamp + "." + rawBody)
 */
export function verifyWebhookSignature({ signature, timestamp, rawBody, secret, maxAge = 300 }) {
  if (!signature || !timestamp || !rawBody || !secret) {
    return { valid: false, error: 'Missing required parameters' };
  }

  const ts = parseInt(timestamp, 10);
  if (isNaN(ts)) {
    return { valid: false, error: 'Invalid timestamp' };
  }

  const age = Math.abs(Date.now() / 1000 - ts);
  if (age > maxAge) {
    return { valid: false, error: 'Timestamp too old (replay protection)' };
  }

  const expected = createHmac('sha256', secret)
    .update(timestamp + '.' + rawBody)
    .digest('hex');

  const valid = timingSafeEqual(
    Buffer.from(expected, 'hex'),
    Buffer.from(signature, 'hex'),
  );

  return valid ? { valid: true } : { valid: false, error: 'Signature mismatch' };
}
