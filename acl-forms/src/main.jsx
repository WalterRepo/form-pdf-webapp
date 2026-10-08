import React from 'react';
import { createRoot } from 'react-dom/client';
import { RegistrationForm } from './registration/RegistrationForm.jsx';
import { ReceiptForm } from './receipts/ReceiptForm.jsx';
import { RenewalForm } from './renewals/RenewalForm.jsx';
import { CertificateForm } from './certificates/CertificateForm.jsx';
import './styles.css';

document.querySelectorAll('.acl-forms-root').forEach((element) => {
  const view = element.dataset.form;
  const component = {
    receipt: <ReceiptForm />,
    renewal: <RenewalForm />,
    certificate: <CertificateForm />
  }[view] || <RegistrationForm />;
  createRoot(element).render(<React.StrictMode>{component}</React.StrictMode>);
});
