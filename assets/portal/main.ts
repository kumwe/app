import './styles.css';
import '../interface-standard/kis-tabs';
import '../interface-standard/kis-master-detail';
import '../interface-standard/kis-drawer';
import { setupCopyValues } from '../interface-standard/copy-value';
import { setupValidationReveal } from '../interface-standard/reveal-validation';

document.documentElement.classList.add('js');
setupCopyValues();
setupValidationReveal();
