import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { Toaster } from 'react-hot-toast';
import { AuthProvider, useAuth } from './context/AuthContext';
import { LangProvider } from './context/LangContext';
import AppLayout from './components/layout/AppLayout';
import LoginPage from './pages/auth/LoginPage';
import DashboardPage from './pages/dashboard/DashboardPage';
import CustomersPage from './pages/customers/CustomersPage';
import CustomerFormPage from './pages/customers/CustomerFormPage';
import CustomerDetailPage from './pages/customers/CustomerDetailPage';
import ProductsPage from './pages/products/ProductsPage';
import ProductFormPage from './pages/products/ProductFormPage';
import ProductDetailPage from './pages/products/ProductDetailPage';
import SuppliersPage from './pages/suppliers/SuppliersPage';
import CategoriesPage from './pages/categories/CategoriesPage';
import BrandsPage from './pages/brands/BrandsPage';
import PurchasesPage from './pages/purchases/PurchasesPage';
import PurchaseFormPage from './pages/purchases/PurchaseFormPage';
import PurchaseDetailPage from './pages/purchases/PurchaseDetailPage';
import OrdersPage from './pages/orders/OrdersPage';
import OrderFormPage from './pages/orders/OrderFormPage';
import OrderDetailPage from './pages/orders/OrderDetailPage';
import ContractsPage from './pages/contracts/ContractsPage';
import ContractDetailPage from './pages/contracts/ContractDetailPage';
import CollectionsPage from './pages/collections/CollectionsPage';
import InvoicesPage from './pages/invoices/InvoicesPage';
import InvoiceDetailPage from './pages/invoices/InvoiceDetailPage';
import ReportsPage from './pages/reports/ReportsPage';
import AssistantPage from './pages/assistant/AssistantPage';
import UsersPage from './pages/users/UsersPage';
import BranchesPage from './pages/branches/BranchesPage';
import SettingsPage from './pages/settings/SettingsPage';
import CashboxPage from './pages/cash/CashboxPage';
import CashTransactionsPage from './pages/cash/CashTransactionsPage';
import JournalEntriesPage from './pages/accounting/JournalEntriesPage';
import VoucherPrintPage from './pages/cash/VoucherPrintPage';
import ExpensesPage from './pages/expenses/ExpensesPage';
import ContractPrintPage from './pages/print/ContractPrintPage';
import InvoicePrintPage from './pages/print/InvoicePrintPage';
import PaymentReceiptPrintPage from './pages/print/PaymentReceiptPrintPage';
import StatementPrintPage from './pages/print/StatementPrintPage';
import PurchaseOrderPrintPage from './pages/print/PurchaseOrderPrintPage';
import PlatformPage from './pages/platform/PlatformPage';

function PrivateRoute({ children }) {
  const { user, loading } = useAuth();
  if (loading) return (
    <div className="min-h-screen flex items-center justify-center">
      <div className="w-10 h-10 border-2 border-primary-600 border-t-transparent rounded-full animate-spin" />
    </div>
  );
  return user ? children : <Navigate to="/login" replace />;
}

function PublicRoute({ children }) {
  const { user, loading } = useAuth();
  if (loading) return null;
  return user ? <Navigate to="/" replace /> : children;
}

function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<PublicRoute><LoginPage /></PublicRoute>} />
      {/* Dedicated print views (no app chrome — A4-friendly) */}
      <Route path="/print/contract/:id" element={<PrivateRoute><ContractPrintPage /></PrivateRoute>} />
      <Route path="/print/invoice/:id" element={<PrivateRoute><InvoicePrintPage /></PrivateRoute>} />
      <Route path="/print/payment/:id" element={<PrivateRoute><PaymentReceiptPrintPage /></PrivateRoute>} />
      <Route path="/print/statement/:id" element={<PrivateRoute><StatementPrintPage /></PrivateRoute>} />
      <Route path="/print/purchase-order/:id" element={<PrivateRoute><PurchaseOrderPrintPage /></PrivateRoute>} />
      <Route path="/cash/voucher/:id" element={<PrivateRoute><VoucherPrintPage /></PrivateRoute>} />
      <Route path="/" element={<PrivateRoute><AppLayout /></PrivateRoute>}>
        <Route index element={<DashboardPage />} />
        <Route path="platform" element={<Navigate to="/platform/overview" replace />} />
        <Route path="platform/:section" element={<PlatformPage />} />
        <Route path="customers" element={<CustomersPage />} />
        <Route path="customers/new" element={<CustomerFormPage />} />
        <Route path="customers/:id/edit" element={<CustomerFormPage />} />
        <Route path="customers/:id" element={<CustomerDetailPage />} />
        <Route path="products" element={<ProductsPage />} />
        <Route path="products/new" element={<ProductFormPage />} />
        <Route path="products/:id/edit" element={<ProductFormPage />} />
        <Route path="products/:id" element={<ProductDetailPage />} />
        <Route path="categories" element={<CategoriesPage />} />
        <Route path="brands" element={<BrandsPage />} />
        <Route path="suppliers" element={<SuppliersPage />} />
        <Route path="purchases/new" element={<PurchaseFormPage />} />
        <Route path="purchases/:id/edit" element={<PurchaseFormPage />} />
        <Route path="purchases/:id" element={<PurchaseDetailPage />} />
        <Route path="purchases" element={<PurchasesPage />} />
        <Route path="cash/transactions" element={<CashTransactionsPage />} />
        <Route path="accounting/journal-entries" element={<JournalEntriesPage />} />
        <Route path="cash" element={<CashboxPage />} />
        <Route path="expenses" element={<ExpensesPage />} />
        <Route path="orders" element={<OrdersPage />} />
        <Route path="orders/new" element={<OrderFormPage />} />
        <Route path="orders/:id" element={<OrderDetailPage />} />
        <Route path="contracts" element={<ContractsPage />} />
        <Route path="contracts/:id" element={<ContractDetailPage />} />
        <Route path="collections" element={<CollectionsPage />} />
        <Route path="invoices" element={<InvoicesPage />} />
        <Route path="invoices/:id" element={<InvoiceDetailPage />} />
        <Route path="reports" element={<ReportsPage />} />
        <Route path="assistant" element={<AssistantPage />} />
        <Route path="users" element={<UsersPage />} />
        <Route path="branches" element={<BranchesPage />} />
        <Route path="settings" element={<SettingsPage />} />
        <Route path="*" element={<Navigate to="/" replace />} />
      </Route>
    </Routes>
  );
}

export default function App() {
  return (
    <LangProvider>
      <AuthProvider>
        <BrowserRouter>
          <AppRoutes />
          <Toaster
            position="top-center"
            toastOptions={{
              duration: 3000,
              style: { borderRadius: '10px', fontSize: '14px' },
              success: { iconTheme: { primary: '#2563eb', secondary: '#fff' } },
            }}
          />
        </BrowserRouter>
      </AuthProvider>
    </LangProvider>
  );
}
