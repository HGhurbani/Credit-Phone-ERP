import axios from 'axios';

export const api = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('token');
      window.location.href = '/login';
    }
    return Promise.reject(error);
  }
);

// API helpers
export const customersApi = {
  list: (params) => api.get('/customers', { params }),
  get: (id) => api.get(`/customers/${id}`),
  create: (data) => api.post('/customers', data),
  update: (id, data) => api.put(`/customers/${id}`, data),
  delete: (id) => api.delete(`/customers/${id}`),
  addNote: (id, note) => api.post(`/customers/${id}/notes`, { note }),
};

export const productsApi = {
  list: (params) => api.get('/products', { params }),
  get: (id) => api.get(`/products/${id}`),
  create: (data) => api.post('/products', data),
  update: (id, data) => api.put(`/products/${id}`, data),
  delete: (id) => api.delete(`/products/${id}`),
  adjustStock: (id, data) => api.post(`/products/${id}/stock`, data),
  categories: () => api.get('/categories'),
  createCategory: (data) => api.post('/categories', data),
  brands: () => api.get('/brands'),
  createBrand: (data) => api.post('/brands', data),
};

export const ordersApi = {
  list: (params) => api.get('/orders', { params }),
  get: (id) => api.get(`/orders/${id}`),
  create: (data) => api.post('/orders', data),
  delete: (id) => api.delete(`/orders/${id}`),
  approve: (id) => api.post(`/orders/${id}/approve`),
  reject: (id, reason) => api.post(`/orders/${id}/reject`, { reason }),
};

export const contractsApi = {
  list: (params) => api.get('/contracts', { params }),
  get: (id) => api.get(`/contracts/${id}`),
  create: (data) => api.post('/contracts', data),
  schedules: (id) => api.get(`/contracts/${id}/schedules`),
};

export const paymentsApi = {
  list: (params) => api.get('/payments', { params }),
  get: (id) => api.get(`/payments/${id}`),
  create: (data) => api.post('/payments', data),
  dueToday: (params) => api.get('/collections/due-today', { params }),
  overdue: (params) => api.get('/collections/overdue', { params }),
};

export const invoicesApi = {
  list: (params) => api.get('/invoices', { params }),
  get: (id) => api.get(`/invoices/${id}`),
  recordPayment: (id, data) => api.post(`/invoices/${id}/payments`, data),
  update: (id, data) => api.patch(`/invoices/${id}`, data),
};

export const branchesApi = {
  list: (params) => api.get('/branches', { params }),
  get: (id) => api.get(`/branches/${id}`),
  create: (data) => api.post('/branches', data),
  update: (id, data) => api.put(`/branches/${id}`, data),
  delete: (id) => api.delete(`/branches/${id}`),
};

export const usersApi = {
  list: (params) => api.get('/users', { params }),
  get: (id) => api.get(`/users/${id}`),
  create: (data) => api.post('/users', data),
  update: (id, data) => api.put(`/users/${id}`, data),
  delete: (id) => api.delete(`/users/${id}`),
  roles: () => api.get('/roles'),
};

export const reportsApi = {
  sales: (params) => api.get('/reports/sales', { params }),
  collections: (params) => api.get('/reports/collections', { params }),
  activeContracts: (params) => api.get('/reports/active-contracts', { params }),
  overdueInstallments: (params) => api.get('/reports/overdue-installments', { params }),
  branchPerformance: (params) => api.get('/reports/branch-performance', { params }),
  agentPerformance: (params) => api.get('/reports/agent-performance', { params }),
};

export const dashboardApi = {
  get: () => api.get('/dashboard'),
};

export const settingsApi = {
  get: () => api.get('/settings'),
  update: (settings) => api.put('/settings', { settings }),
};
