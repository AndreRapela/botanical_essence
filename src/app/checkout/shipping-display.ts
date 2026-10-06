import { Language } from '../storefront.models';
import { ShippingQuote } from '../storefront.models';

function copy(language: Language, english: string, french: string): string {
  return language === 'fr' ? french : english;
}

export function shippingLabel(quote: ShippingQuote, language: Language): string {
  if (quote.id === 'bpost_pickup') return copy(language, 'Pick-up Point or Parcel Locker', 'Point d’enlèvement ou Distributeur de Colis');
  if (quote.id === 'bpost_home_guaranteed') return copy(language, 'Home delivery with warranty', 'Livraison à domicile avec garantie');
  if (quote.id === 'bpost_home') return copy(language, 'Home delivery', 'Livraison à domicile');
  return copy(language, 'International home delivery', 'Livraison internationale à domicile');
}

export function shippingDescription(quote: ShippingQuote, language: Language): string {
  if (quote.id === 'bpost_pickup') return copy(language, 'Estimate only · no pick-up point is selected here', 'Estimation uniquement · aucun point d’enlèvement n’est sélectionné ici');
  if (quote.id === 'bpost_home_guaranteed') return copy(language, 'Estimate only · warranty is not purchased here', 'Estimation uniquement · la garantie n’est pas achetée ici');
  return copy(language, 'Estimate only · no label or tracking is created here', 'Estimation uniquement · aucune étiquette ni aucun suivi n’est créé ici');
}
