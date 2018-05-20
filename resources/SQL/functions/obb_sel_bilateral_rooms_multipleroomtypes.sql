-- FUNCTION: salesforcetraining2.obb_sel_bilateral_rooms(character, character varying[])

-- DROP FUNCTION salesforcetraining2.obb_sel_bilateral_rooms(character, character varying[]);

CREATE OR REPLACE FUNCTION salesforcetraining2.obb_sel_bilateral_rooms(
  IN event_id character,
  IN p_room_type character varying[])
    RETURNS TABLE(room_id character varying, room_name character varying, room_type character varying) AS
  $BODY$
DECLARE
/*
    Author: YCH
    History: V1.0  Dec 16 2015, Allow to have an array as input, e.g. '{Public Figures,Business'}
*/
BEGIN
	RETURN QUERY SELECT r.sfid, r.Name, rs.set_of_participants__c as room_type
	FROM programmeroomsetup__c rs
	INNER JOIN Programme__c p on (rs.programme_name__c = p.sfid)
	INNER JOIN Room__c r ON rs.room_name__c = r.sfid
	WHERE p.event__c = event_id
	AND p.type__c = 'Bilateral'
	AND rs.set_of_participants__c = ANY (p_room_type);
--	AND rs.set_of_participants__c IN (' || p_room_type || ');
END;
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 100
  ROWS 1000;

