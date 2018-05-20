-- Function: salesforcetraining2.obb_sel_bilateral_availability(character)

-- DROP FUNCTION salesforcetraining2.obb_sel_bilateral_availability(character);

CREATE OR REPLACE FUNCTION salesforcetraining2.obb_sel_bilateral_availability(IN event_id character)
  RETURNS TABLE(start_day character varying, start_time timestamp without time zone, end_time timestamp without time zone) AS
$BODY$
DECLARE
BEGIN
	RETURN QUERY SELECT ba.date__c::character varying(11), (ba.date__c || ' ' || ba.start_time__c)::timestamp, (ba.date__c || ' ' || ba.end_time__c)::timestamp
	FROM bilateral_availability__c ba
	INNER JOIN programme__c p on (ba.programme_name__c = p.sfid)
	WHERE p.event__c = event_id
	AND p.type__c = 'Bilateral'
	AND ba.isDeleted = FALSE
	ORDER BY (ba.date__c || ' ' || ba.start_time__c)::timestamp;
END;
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 100
  ROWS 1000;
ALTER FUNCTION salesforcetraining2.obb_sel_bilateral_availability(character)
  OWNER TO ue6lindbc6iam1;
COMMENT ON FUNCTION salesforcetraining2.obb_sel_bilateral_availability(character) IS 'TBB Availability for a given event';

-- SET search_path = salesforcetraining2, public;
-- SELECT * from salesforcetraining2.obb_sel_bilateral_availability('a0Pb0000000GePmEAK');
