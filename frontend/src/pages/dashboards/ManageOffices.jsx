import LookupManager from './LookupManager';

const CONFIG = {
  key: 'offices',
  label: 'Offices',
  singular: 'Office',
  listEndpoint: '/offices',
  createEndpoint: '/admin/offices',
  updateEndpoint: (id) => `/admin/offices/${id}`,
  nameField: 'office_name',
  codeField: 'office_code',
  nameLabel: 'Office name',
  codeLabel: 'Office code',
};

export default function ManageOffices() {
  return <LookupManager config={CONFIG} />;
}
