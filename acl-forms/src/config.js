export function getConfig() {
  if (!window.ACLFormsConfig) {
    throw new Error('Configurazione ACL Forms non disponibile.');
  }
  return window.ACLFormsConfig;
}
