import LookupManager from './LookupManager';

const CONFIG = {
  key: 'requestTypes',
  label: 'Request types',
  singular: 'Request type',
  listEndpoint: '/request-types',
  createEndpoint: '/admin/request-types',
  updateEndpoint: (id) => `/admin/request-types/${id}`,
  nameField: 'type_name',
  codeField: 'type_code',
  nameLabel: 'Request type name',
  codeLabel: 'Request type code',
};

export default function ManageRequestTypes() {
  return <LookupManager config={CONFIG} />;
}
