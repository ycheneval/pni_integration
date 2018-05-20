-- Function: salesforcetraining2.obb_count_bilateral_meeting(character varying, character varying, boolean)

-- DROP FUNCTION salesforcetraining2.obb_count_bilateral_meeting(character varying, character varying, boolean);

CREATE OR REPLACE FUNCTION salesforcetraining2.obb_count_bilateral_meeting(IN event_sfid character varying, IN account_sfid character varying, IN type_public_figure boolean)
  RETURNS TABLE(count bigint) AS
$BODY$
DECLARE
/*
    Author: BMU
    History:    V1.0 BMU Jul 02 2015: Creation
		V1.1 YCH Nov 09 2015, Corrected to include rhost.type__c is 'Hosted by'
*/
BEGIN

IF type_public_figure = TRUE THEN
	RETURN QUERY SELECT COUNT(DISTINCT s.sfid)
			FROM session__c s
			INNER JOIN role__c rguest ON (rguest.session__c = s.sfid) AND rguest.type__c = 'With'
			INNER JOIN role__c rhost ON (rhost.session__c = s.sfid) AND rhost.type__c = 'Hosted by'
			INNER JOIN opportunity ohost ON (rhost.eventopportunity__c = ohost.sfid)
			INNER JOIN opportunity oguest ON (rguest.eventopportunity__c = oguest.sfid)
			INNER JOIN Position__c phost ON (ohost.Position__c = phost.sfid)
			INNER JOIN Position__c phost2 ON (phost.Top_Level_Organization__c = phost2.Top_Level_Organization__c)
			INNER JOIN account host ON (phost2.sfid = host.primarypositionlookup__c)
			WHERE s.event__c = event_sfid
			AND s.type__c IN ('Bilateral Meeting')
			AND s.status__c  IN ('Open', 'Pending Request')
			AND oguest.identified_as_public_figure__c = TRUE
			AND host.sfid = account_sfid;
ELSE
	RETURN QUERY SELECT COUNT(DISTINCT s.sfid)
			FROM session__c s
			INNER JOIN role__c rguest ON (rguest.session__c = s.sfid) AND rguest.type__c = 'With'
			INNER JOIN role__c rhost ON (rhost.session__c = s.sfid) AND rhost.type__c = 'Hosted by'
			INNER JOIN opportunity ohost ON (rhost.eventopportunity__c = ohost.sfid)
			INNER JOIN opportunity oguest ON (rguest.eventopportunity__c = oguest.sfid)
			INNER JOIN Position__c phost ON (ohost.Position__c = phost.sfid)
			INNER JOIN Position__c phost2 ON (phost.Top_Level_Organization__c = phost2.Top_Level_Organization__c)
			INNER JOIN account host ON (phost2.sfid = host.primarypositionlookup__c)
			WHERE s.event__c = event_sfid
			AND s.type__c IN ('Bilateral Meeting')
			AND s.status__c  IN ('Open')
			AND oguest.identified_as_public_figure__c = FALSE
			AND host.sfid = account_sfid;
END IF;


END;
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 100
  ROWS 1000;
ALTER FUNCTION salesforcetraining2.obb_count_bilateral_meeting(character varying, character varying, boolean)
  OWNER TO ue6lindbc6iam1;
