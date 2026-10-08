import React from 'react';

export function Status({ state }) {
  if (!state?.message) return null;
  return <div className={`acl-status acl-status--${state.type || 'info'}`} role="status">{state.message}</div>;
}
