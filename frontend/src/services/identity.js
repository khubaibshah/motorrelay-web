import api from './api';

/** Start a Stripe-hosted identity verification session for the signed-in user. */
export async function startIdentityVerification() {
  const { data } = await api.post('/stripe/identity/session');
  return data;
}
