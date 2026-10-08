import { getConfig } from './config.js';

async function request(path, options = {}) {
  const config = getConfig();
  const isFormData = options.body instanceof FormData;
  const response = await fetch(`${config.restUrl}${path}`, {
    ...options,
    credentials: 'same-origin',
    headers: {
      ...(!isFormData ? { 'Content-Type': 'application/json' } : {}),
      'X-ACL-Nonce': config.publicNonce,
      'X-WP-Nonce': config.restNonce,
      ...(options.headers || {})
    }
  });

  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(payload.message || payload.error || 'Operazione non riuscita.');
    error.diagnostics = payload.data?.diagnostics || null;
    throw error;
  }
  return payload;
}

export const api = {
  submitRegistration: (body) => request('/registration', { method: 'POST', body: JSON.stringify(body) }),
  getReceiptInit: () => request('/receipts/init'),
  submitReceipt: (body) => request('/receipts', { method: 'POST', body: JSON.stringify(body) }),
  verifyRenewal: (codiceFiscale) => request('/renewals/verify', { method: 'POST', body: JSON.stringify({ codiceFiscale }) }),
  submitRenewal: (body) => request('/renewals', { method: 'POST', body: JSON.stringify(body) }),
  verifyCertificate: (taxCode) => request('/certificates/verify', { method: 'POST', body: JSON.stringify({ taxCode }) }),
  submitCertificate: (body) => request('/certificates', { method: 'POST', body })
};
