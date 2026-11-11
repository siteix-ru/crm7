<script lang="ts">
  import ArrowDownOutlined from '@ant-design/icons-svg/es/asn/ArrowDownOutlined';
  import ArrowLeftOutlined from '@ant-design/icons-svg/es/asn/ArrowLeftOutlined';
  import ArrowRightOutlined from '@ant-design/icons-svg/es/asn/ArrowRightOutlined';
  import ArrowUpOutlined from '@ant-design/icons-svg/es/asn/ArrowUpOutlined';
  import AppstoreOutlined from '@ant-design/icons-svg/es/asn/AppstoreOutlined';
  import AuditOutlined from '@ant-design/icons-svg/es/asn/AuditOutlined';
  import BarChartOutlined from '@ant-design/icons-svg/es/asn/BarChartOutlined';
  import BellOutlined from '@ant-design/icons-svg/es/asn/BellOutlined';
  import CalendarOutlined from '@ant-design/icons-svg/es/asn/CalendarOutlined';
  import CameraOutlined from '@ant-design/icons-svg/es/asn/CameraOutlined';
  import CheckCircleFilled from '@ant-design/icons-svg/es/asn/CheckCircleFilled';
  import CheckOutlined from '@ant-design/icons-svg/es/asn/CheckOutlined';
  import CloseCircleFilled from '@ant-design/icons-svg/es/asn/CloseCircleFilled';
  import CloseOutlined from '@ant-design/icons-svg/es/asn/CloseOutlined';
  import CommentOutlined from '@ant-design/icons-svg/es/asn/CommentOutlined';
  import CopyOutlined from '@ant-design/icons-svg/es/asn/CopyOutlined';
  import DatabaseOutlined from '@ant-design/icons-svg/es/asn/DatabaseOutlined';
  import DeleteOutlined from '@ant-design/icons-svg/es/asn/DeleteOutlined';
  import HistoryOutlined from '@ant-design/icons-svg/es/asn/HistoryOutlined';
  import DollarOutlined from '@ant-design/icons-svg/es/asn/DollarOutlined';
  import DownloadOutlined from '@ant-design/icons-svg/es/asn/DownloadOutlined';
  import EditOutlined from '@ant-design/icons-svg/es/asn/EditOutlined';
  import FileAddOutlined from '@ant-design/icons-svg/es/asn/FileAddOutlined';
  import FileTextOutlined from '@ant-design/icons-svg/es/asn/FileTextOutlined';
  import FilterOutlined from '@ant-design/icons-svg/es/asn/FilterOutlined';
  import GroupOutlined from '@ant-design/icons-svg/es/asn/GroupOutlined';
  import HolderOutlined from '@ant-design/icons-svg/es/asn/HolderOutlined';
  import IdcardOutlined from '@ant-design/icons-svg/es/asn/IdcardOutlined';
  import ImportOutlined from '@ant-design/icons-svg/es/asn/ImportOutlined';
  import InfoCircleOutlined from '@ant-design/icons-svg/es/asn/InfoCircleOutlined';
  import KeyOutlined from '@ant-design/icons-svg/es/asn/KeyOutlined';
  import LogoutOutlined from '@ant-design/icons-svg/es/asn/LogoutOutlined';
  import MailOutlined from '@ant-design/icons-svg/es/asn/MailOutlined';
  import MenuOutlined from '@ant-design/icons-svg/es/asn/MenuOutlined';
  import MessageOutlined from '@ant-design/icons-svg/es/asn/MessageOutlined';
  import PaperClipOutlined from '@ant-design/icons-svg/es/asn/PaperClipOutlined';
  import PieChartOutlined from '@ant-design/icons-svg/es/asn/PieChartOutlined';
  import PictureOutlined from '@ant-design/icons-svg/es/asn/PictureOutlined';
  import PlusOutlined from '@ant-design/icons-svg/es/asn/PlusOutlined';
  import ProfileOutlined from '@ant-design/icons-svg/es/asn/ProfileOutlined';
  import QuestionCircleOutlined from '@ant-design/icons-svg/es/asn/QuestionCircleOutlined';
  import ReloadOutlined from '@ant-design/icons-svg/es/asn/ReloadOutlined';
  import SafetyCertificateOutlined from '@ant-design/icons-svg/es/asn/SafetyCertificateOutlined';
  import SendOutlined from '@ant-design/icons-svg/es/asn/SendOutlined';
  import SettingOutlined from '@ant-design/icons-svg/es/asn/SettingOutlined';
  import ShopOutlined from '@ant-design/icons-svg/es/asn/ShopOutlined';
  import SlidersOutlined from '@ant-design/icons-svg/es/asn/SlidersOutlined';
  import ShoppingCartOutlined from '@ant-design/icons-svg/es/asn/ShoppingCartOutlined';
  import SortAscendingOutlined from '@ant-design/icons-svg/es/asn/SortAscendingOutlined';
  import SearchOutlined from '@ant-design/icons-svg/es/asn/SearchOutlined';
  import SwapOutlined from '@ant-design/icons-svg/es/asn/SwapOutlined';
  import TableOutlined from '@ant-design/icons-svg/es/asn/TableOutlined';
  import TagsOutlined from '@ant-design/icons-svg/es/asn/TagsOutlined';
  import TeamOutlined from '@ant-design/icons-svg/es/asn/TeamOutlined';
  import UnorderedListOutlined from '@ant-design/icons-svg/es/asn/UnorderedListOutlined';
  import UserAddOutlined from '@ant-design/icons-svg/es/asn/UserAddOutlined';
  import UserOutlined from '@ant-design/icons-svg/es/asn/UserOutlined';
  import type { IconDefinition } from '@ant-design/icons-svg/es/types';
  import { renderIconDefinitionToSVGElement } from '@ant-design/icons-svg/es/helpers';

  interface Props {
    name: string;
    size?: number;
  }

  let { name, size = 20 }: Props = $props();

  const icons: Record<string, IconDefinition> = {
    admin: SafetyCertificateOutlined,
    'arrow-down': ArrowDownOutlined,
    'arrow-left': ArrowLeftOutlined,
    'arrow-right': ArrowRightOutlined,
    'arrow-up': ArrowUpOutlined,
    audit: AuditOutlined,
    bell: BellOutlined,
    calendar: CalendarOutlined,
    camera: CameraOutlined,
    cart: ShoppingCartOutlined,
    chat: MessageOutlined,
    'check-circle': CheckCircleFilled,
    check: CheckOutlined,
    clients: TeamOutlined,
    close: CloseOutlined,
    'close-circle': CloseCircleFilled,
    comments: CommentOutlined,
    copy: CopyOutlined,
    database: DatabaseOutlined,
    download: DownloadOutlined,
    edit: EditOutlined,
    events: AuditOutlined,
    files: PaperClipOutlined,
    history: HistoryOutlined,
    overview: ProfileOutlined,
    export: DownloadOutlined,
    filter: FilterOutlined,
    'file-plus': FileAddOutlined,
    group: GroupOutlined,
    holder: HolderOutlined,
    help: QuestionCircleOutlined,
    import: ImportOutlined,
    info: InfoCircleOutlined,
    key: KeyOutlined,
    logout: LogoutOutlined,
    mail: MailOutlined,
    menu: MenuOutlined,
    'my-reports': PieChartOutlined,
    picture: PictureOutlined,
    price: DollarOutlined,
    search: SearchOutlined,
    send: SendOutlined,
    plus: PlusOutlined,
    refresh: ReloadOutlined,
    reports: BarChartOutlined,
    requests: FileTextOutlined,
    settings: SettingOutlined,
    sliders: SlidersOutlined,
    sort: SortAscendingOutlined,
    staff: IdcardOutlined,
    stocks: DatabaseOutlined,
    substitutions: SwapOutlined,
    tags: TagsOutlined,
    trash: DeleteOutlined,
    types: AppstoreOutlined,
    list: UnorderedListOutlined,
    tiles: AppstoreOutlined,
    columns: TableOutlined,
    user: UserOutlined,
    'user-plus': UserAddOutlined,
    view: TableOutlined,
    warehouse: ShopOutlined
  };

  const svg = $derived(
    renderIconDefinitionToSVGElement(icons[name] ?? QuestionCircleOutlined, {
      extraSVGAttrs: {
        width: String(size),
        height: String(size),
        fill: 'currentColor',
        'aria-hidden': 'true'
      }
    })
  );
</script>

{@html svg}
