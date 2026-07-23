import api from '@/services/api';

export async function fetchDriverLicenceVerification() {
  const { data } = await api.get('/driver/licence-verification');
  return data.verification;
}

export async function submitDriverLicenceVerification(payload) {
  const { data } = await api.post('/driver/licence-verification', payload);
  return data.verification;
}
