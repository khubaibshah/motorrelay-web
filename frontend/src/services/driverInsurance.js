import api from '@/services/api';

export async function fetchDriverInsuranceVerification() {
  const { data } = await api.get('/driver/insurance-verification');
  return data.verification;
}

export async function submitDriverInsuranceVerification(form) {
  const { data } = await api.post('/driver/insurance-verification', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return data.verification;
}
